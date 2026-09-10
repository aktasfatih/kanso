<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service\Mail;

use OCA\Kanso\Service\Mail\AuthenticationResults;
use PHPUnit\Framework\TestCase;

class AuthenticationResultsTest extends TestCase {
	public function testDmarcPassForTheMatchingDomainAuthenticates(): void {
		$results = AuthenticationResults::fromHeader('mx.ours.test; dmarc=pass header.from=example.com');

		self::assertTrue($results->authenticates('jacek@example.com'));
	}

	public function testDmarcPassIsCaseInsensitive(): void {
		$results = AuthenticationResults::fromHeader('mx.ours.test; DMARC=PASS header.from=Example.COM');

		self::assertTrue($results->authenticates('Jacek@Example.com'));
	}

	public function testDmarcPassForAnotherDomainDoesNotVouchForThisSender(): void {
		// Without the header.from cross-check, any message carrying a pass for
		// any domain would authenticate every sender.
		$results = AuthenticationResults::fromHeader('mx.ours.test; dmarc=pass header.from=attacker.test');

		self::assertFalse($results->authenticates('jacek@example.com'));
	}

	public function testSubdomainOfThePassingDomainIsAligned(): void {
		// DMARC alignment allows the organisational domain to cover subdomains.
		$results = AuthenticationResults::fromHeader('mx.ours.test; dmarc=pass header.from=example.com');

		self::assertTrue($results->authenticates('bot@mail.example.com'));
	}

	public function testALookalikeSuffixIsNotAligned(): void {
		// 'notexample.com' ends with 'example.com' as a STRING but is a different
		// domain; alignment requires a label boundary.
		$results = AuthenticationResults::fromHeader('mx.ours.test; dmarc=pass header.from=example.com');

		self::assertFalse($results->authenticates('sneaky@notexample.com'));
	}

	public function testSpfPassAloneDoesNotAuthenticate(): void {
		// SPF authenticates the ENVELOPE sender, which can differ entirely from
		// the visible From that a person reads and the allowlist matches.
		$results = AuthenticationResults::fromHeader('mx.ours.test; spf=pass smtp.mailfrom=bounce.example.com');

		self::assertFalse($results->authenticates('jacek@example.com'));
	}

	public function testDkimPassAloneDoesNotAuthenticate(): void {
		$results = AuthenticationResults::fromHeader('mx.ours.test; dkim=pass header.d=example.com');

		self::assertFalse($results->authenticates('jacek@example.com'));
	}

	public function testDmarcFailDoesNotAuthenticate(): void {
		$results = AuthenticationResults::fromHeader('mx.ours.test; dmarc=fail header.from=example.com');

		self::assertFalse($results->authenticates('jacek@example.com'));
	}

	public function testAbsentHeaderDoesNotAuthenticate(): void {
		// No verdict is not a pass - a deployment with no authenticating MTA must
		// not silently accept everything.
		$results = AuthenticationResults::fromHeader('');

		self::assertFalse($results->authenticates('jacek@example.com'));
	}

	public function testDmarcPassWithNoHeaderFromCannotBeTiedToTheSender(): void {
		$results = AuthenticationResults::fromHeader('mx.ours.test; dmarc=pass');

		self::assertFalse($results->authenticates('jacek@example.com'));
	}

	public function testAddressWithoutADomainDoesNotAuthenticate(): void {
		$results = AuthenticationResults::fromHeader('mx.ours.test; dmarc=pass header.from=example.com');

		self::assertFalse($results->authenticates('not-an-address'));
	}

	public function testReadsAMixedResultHeader(): void {
		$raw = 'mx.ours.test; spf=fail smtp.mailfrom=x.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com';
		$results = AuthenticationResults::fromHeader($raw);

		self::assertSame('fail', $results->result('spf'));
		self::assertSame('pass', $results->result('dkim'));
		self::assertSame('pass', $results->result('dmarc'));
		self::assertTrue($results->authenticates('a@example.com'));
	}

	public function testPropertyNamesAreNotMistakenForMethodResults(): void {
		// 'header.d=example.com' and friends must not be read as further verdicts.
		$results = AuthenticationResults::fromHeader('mx.ours.test; dkim=fail header.d=dmarc.example.com');

		self::assertSame('', $results->result('dmarc'));
		self::assertFalse($results->authenticates('a@dmarc.example.com'));
	}

	public function testGarbageHeaderIsInert(): void {
		$results = AuthenticationResults::fromHeader('!!! not a header at all ???');

		self::assertFalse($results->authenticates('a@example.com'));
		self::assertSame('', $results->result('dmarc'));
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service\Mail;

use OCA\Kanso\Service\Mail\ImapException;
use OCA\Kanso\Service\Mail\MailHostGuard;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The guard that stops "configure a mailbox" from meaning "make the server
 * open a connection to any address you name and tell you how it went".
 *
 * Uses IP literals throughout: the point under test is the verdict, and going
 * through real DNS would make the suite depend on a resolver.
 */
class MailHostGuardTest extends TestCase {
	private IConfig&MockObject $config;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
	}

	private function guard(bool $allowPrivate = false): MailHostGuard {
		$this->config->method('getAppValue')->willReturn($allowPrivate ? 'yes' : 'no');
		return new MailHostGuard($this->config);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function blockedAddresses(): array {
		return [
			'loopback v4' => ['127.0.0.1'],
			'loopback v4, other octet' => ['127.99.1.5'],
			'loopback v6' => ['::1'],
			'private 10/8' => ['10.0.0.51'],
			'private 172.16/12' => ['172.16.4.9'],
			'private 192.168/16' => ['192.168.2.170'],
			// The cloud metadata endpoint - the single most valuable SSRF target.
			'cloud metadata' => ['169.254.169.254'],
			'link-local v6' => ['fe80::1'],
			'unique local v6' => ['fd00::1'],
			'unspecified' => ['0.0.0.0'],
			// An IPv4 address wearing an IPv6 costume, which some validators wave
			// through.
			'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
			'ipv4-mapped private' => ['::ffff:10.0.0.51'],
		];
	}

	/**
	 * @dataProvider blockedAddresses
	 */
	public function testRejectsAddressesTheServerMustNotDial(string $address): void {
		$this->expectException(ImapException::class);
		$this->guard()->resolve($address);
	}

	public function testAllowsAPublicAddress(): void {
		self::assertSame('198.51.100.7', $this->guard()->resolve('198.51.100.7'));
	}

	public function testAllowsAPublicIpv6Address(): void {
		self::assertSame('2001:db8::1', $this->guard()->resolve('2001:db8::1'));
	}

	public function testAdminOptInAllowsPrivateRanges(): void {
		// "Our IMAP server is on the LAN" is legitimate - but it takes an
		// instance-wide admin setting, not a board manager filling in a form.
		self::assertSame('192.168.2.170', $this->guard(allowPrivate: true)->resolve('192.168.2.170'));
	}

	public function testAdminOptInStillRefusesLoopback(): void {
		// There is no legitimate "my IMAP server is this very process", and
		// loopback is where admin panels bind.
		$this->expectException(ImapException::class);
		$this->guard(allowPrivate: true)->resolve('127.0.0.1');
	}

	public function testAdminOptInStillRefusesIpv6Loopback(): void {
		$this->expectException(ImapException::class);
		$this->guard(allowPrivate: true)->resolve('::1');
	}

	public function testRejectsAnEmptyHost(): void {
		$this->expectException(ImapException::class);
		$this->guard()->resolve('   ');
	}

	public function testRejectsAHostThatDoesNotResolve(): void {
		// .invalid is reserved by RFC 2606 precisely so it can never resolve.
		$this->expectException(ImapException::class);
		$this->guard()->resolve('nonexistent-host.invalid');
	}

	public function testTheErrorNamesTheOptInSoAnAdminKnowsWhatToDo(): void {
		try {
			$this->guard()->resolve('10.0.0.51');
			self::fail('Expected an ImapException');
		} catch (ImapException $e) {
			self::assertStringContainsString('mail_intake_allow_private_hosts', $e->getMessage());
		}
	}
}

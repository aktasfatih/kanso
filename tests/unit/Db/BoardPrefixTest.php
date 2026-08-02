<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\BoardPrefix;
use PHPUnit\Framework\TestCase;

/**
 * The human-id prefix helper: deterministic derivation from a title and
 * normalization of a user-supplied prefix. Both are the single source of truth
 * shared by board create, board settings and the backfill migration.
 */
class BoardPrefixTest extends TestCase {
	public function testFromTitleTakesLeadingLetters(): void {
		self::assertSame('MYBOA', BoardPrefix::fromTitle('My board'));
		self::assertSame('KANSO', BoardPrefix::fromTitle('Kanso'));
	}

	public function testFromTitleSkipsNonLettersAndCapsLength(): void {
		// Digits/punctuation/spaces are skipped; result is capped at MAX_LENGTH.
		self::assertSame('SPRIN', BoardPrefix::fromTitle('Sprint 2026 - Q3'));
		self::assertSame('AB', BoardPrefix::fromTitle('a-b'));
	}

	public function testFromTitleFallsBackToDefaultWhenNoLetters(): void {
		self::assertSame(BoardPrefix::DEFAULT, BoardPrefix::fromTitle('123 - 456'));
		self::assertSame(BoardPrefix::DEFAULT, BoardPrefix::fromTitle('   '));
		self::assertSame(BoardPrefix::DEFAULT, BoardPrefix::fromTitle(''));
	}

	public function testNormalizeUppercasesTrimsAndCaps(): void {
		self::assertSame('MYPRO', BoardPrefix::normalize('my proj'));
		self::assertSame('KAN', BoardPrefix::normalize('  kan  '));
		// Digits are allowed in a user-supplied prefix (unlike title derivation).
		self::assertSame('V2ABC', BoardPrefix::normalize('v2abcdef'));
	}

	public function testNormalizeReturnsNullForEmptyResult(): void {
		self::assertNull(BoardPrefix::normalize('---'));
		self::assertNull(BoardPrefix::normalize('   '));
		self::assertNull(BoardPrefix::normalize(''));
	}
}

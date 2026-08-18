<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\TitleSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure-helper coverage for the multibyte-safe title hardening used by the
 * import paths. Kanso's `title` columns are STRING(100); this helper guarantees
 * an over-long or empty source title never overflows the varchar or drops a
 * card/stack/label, and never cuts a multibyte grapheme into mojibake.
 */
class TitleSanitizerTest extends TestCase {
	// ---- truncate(): under / at / over limit ----------------------------

	public function testTruncateUnderLimitUnchanged(): void {
		self::assertSame('short title', TitleSanitizer::truncate('short title', 100));
		self::assertSame('abc', TitleSanitizer::truncate('abc', 3));
	}

	public function testTruncateExactlyAtLimitUnchanged(): void {
		$title = str_repeat('a', 100);
		self::assertSame($title, TitleSanitizer::truncate($title, 100));
		self::assertSame(100, mb_strlen(TitleSanitizer::truncate($title, 100)));
	}

	public function testTruncateOverLimitCutToMax(): void {
		$title = str_repeat('a', 250);
		$out = TitleSanitizer::truncate($title, 100);
		self::assertSame(100, mb_strlen($out));
		self::assertSame(str_repeat('a', 100), $out);
	}

	public function testTruncateTrimsSurroundingWhitespaceBeforeMeasuring(): void {
		// Leading/trailing whitespace is trimmed first, so this fits under 10.
		self::assertSame('hello', TitleSanitizer::truncate('   hello   ', 10));
	}

	// ---- truncate(): empty / whitespace -> placeholder ------------------

	public function testTruncateEmptyReturnsPlaceholder(): void {
		self::assertSame('Untitled', TitleSanitizer::truncate('', 100));
		self::assertSame('Untitled', TitleSanitizer::truncate('     ', 100));
		self::assertSame('Untitled', TitleSanitizer::truncate("\t\n ", 100));
	}

	public function testTruncateCustomPlaceholder(): void {
		self::assertSame('No name', TitleSanitizer::truncate('   ', 100, 'No name'));
	}

	public function testTruncateRtrimAfterCutStripsTrailingSpaces(): void {
		// 3 letters then many spaces, cut to 5 chars -> 'foo  ' -> rtrim -> 'foo'.
		self::assertSame('foo', TitleSanitizer::truncate('foo' . str_repeat(' ', 20), 5));
	}

	public function testTruncateSliceThatRtrimsToEmptyFallsBackToPlaceholder(): void {
		// Non-empty after the initial trim, but the retained mb_substr slice is
		// entirely whitespace, so rtrim() empties it -> the guard returns the
		// placeholder. max=0 makes the slice '' deterministically.
		self::assertSame('Untitled', TitleSanitizer::truncate('word', 0));
		self::assertSame('No name', TitleSanitizer::truncate('word', 0, 'No name'));
	}

	// ---- truncate(): multibyte / emoji boundary safety ------------------

	public function testTruncateMultibyteCutsOnCharacterBoundary(): void {
		// 200 'é' (each 2 bytes in UTF-8) truncated to 100 chars.
		$title = str_repeat('é', 200);
		$out = TitleSanitizer::truncate($title, 100);
		self::assertSame(100, mb_strlen($out), 'measured in characters, not bytes');
		self::assertSame(str_repeat('é', 100), $out);
		// No broken byte sequence: a valid-UTF-8 round-trip yields the same bytes.
		self::assertSame($out, mb_convert_encoding($out, 'UTF-8', 'UTF-8'), 'no mojibake');
	}

	public function testTruncateEmojiCutOnGraphemeBoundaryNoBrokenBytes(): void {
		// 50 camera emoji (each a 4-byte sequence) truncated to 10 chars.
		$title = str_repeat('📷', 50);
		$out = TitleSanitizer::truncate($title, 10);
		self::assertLessThanOrEqual(10, mb_strlen($out));
		self::assertSame(10, mb_strlen($out));
		self::assertSame(str_repeat('📷', 10), $out);
		// The output must still be valid UTF-8 (no mid-sequence split).
		self::assertNotFalse(mb_check_encoding($out, 'UTF-8'));
		self::assertSame($out, mb_convert_encoding($out, 'UTF-8', 'UTF-8'));
	}

	public function testTruncateUnicodeUnderLimitPreserved(): void {
		$title = 'Проект 報告 café 📊';
		self::assertSame($title, TitleSanitizer::truncate($title, 100));
	}

	// ---- isOverLength() -------------------------------------------------

	public function testIsOverLengthUnderAndAtLimitIsFalse(): void {
		self::assertFalse(TitleSanitizer::isOverLength('abc', 3));
		self::assertFalse(TitleSanitizer::isOverLength(str_repeat('a', 100), 100));
		self::assertFalse(TitleSanitizer::isOverLength('', 100));
	}

	public function testIsOverLengthOverLimitIsTrue(): void {
		self::assertTrue(TitleSanitizer::isOverLength(str_repeat('a', 101), 100));
		self::assertTrue(TitleSanitizer::isOverLength('abcd', 3));
	}

	public function testIsOverLengthTrimsBeforeMeasuring(): void {
		// 3 real chars padded with whitespace is NOT over a limit of 3.
		self::assertFalse(TitleSanitizer::isOverLength('   abc   ', 3));
	}

	public function testIsOverLengthCountsCharactersNotBytes(): void {
		// 100 two-byte 'é' == 100 characters, not over a limit of 100.
		self::assertFalse(TitleSanitizer::isOverLength(str_repeat('é', 100), 100));
		self::assertTrue(TitleSanitizer::isOverLength(str_repeat('é', 101), 100));
	}

	public function testIsOverLengthMatchesTruncateDecision(): void {
		// Contract: isOverLength(t,max) is true iff truncate would shorten it.
		$cases = [
			['short', 100],
			[str_repeat('x', 100), 100],
			[str_repeat('x', 101), 100],
			[str_repeat('é', 150), 100],
		];
		foreach ($cases as [$title, $max]) {
			$over = TitleSanitizer::isOverLength($title, $max);
			$shortened = TitleSanitizer::truncate($title, $max) !== trim($title);
			self::assertSame($over, $shortened, "mismatch for '$title'");
		}
	}
}

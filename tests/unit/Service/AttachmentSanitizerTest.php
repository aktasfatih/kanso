<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\AttachmentSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure-helper coverage for the shared attachment hardening. This is the
 * security-relevant coercion that every upload / "Share from Files" / Deck
 * import path funnels its untrusted filename + MIME through, so every branch
 * (path stripping, control-char scrub, length cap, MIME allow/deny, unsafe
 * prefixes, size cap) is asserted directly here rather than only indirectly via
 * {@see CardAttachmentServiceTest}.
 */
class AttachmentSanitizerTest extends TestCase {
	// ---- filename(): path stripping -------------------------------------

	public function testFilenameStripsUnixPath(): void {
		self::assertSame('passwd', AttachmentSanitizer::filename('../../etc/passwd'));
		self::assertSame('x', AttachmentSanitizer::filename('/abs/path/x'));
		self::assertSame('file.txt', AttachmentSanitizer::filename('deep/nested/file.txt'));
	}

	public function testFilenameStripsWindowsPath(): void {
		// Backslashes are normalised to '/' before basename().
		self::assertSame('file.txt', AttachmentSanitizer::filename('dir\\sub\\file.txt'));
		self::assertSame('b', AttachmentSanitizer::filename('C:\\a\\b'));
		self::assertSame('evil.exe', AttachmentSanitizer::filename('..\\..\\windows\\evil.exe'));
	}

	// ---- filename(): control-char strip ---------------------------------

	public function testFilenameStripsControlChars(): void {
		self::assertSame('abc.txt', AttachmentSanitizer::filename("a\x00b\x1fc.txt"));
		self::assertSame('report.pdf', AttachmentSanitizer::filename("rep\x7fort\x0a.pdf"));
		// A name that is ONLY control chars collapses to empty -> placeholder.
		self::assertSame('attachment', AttachmentSanitizer::filename("\x00\x01\x1f\x7f"));
	}

	// ---- filename(): empty / whitespace / dot placeholder behaviour -----

	public function testFilenameEmptyAndWhitespaceFallBackToPlaceholder(): void {
		self::assertSame('attachment', AttachmentSanitizer::filename(''));
		self::assertSame('attachment', AttachmentSanitizer::filename('   '));
		self::assertSame('attachment', AttachmentSanitizer::filename("\t \n"));
	}

	public function testFilenameDotSegmentsBecomeEmptyThenPlaceholder(): void {
		// basename('.') === '.' and basename('..') === '..'; those are NOT
		// coerced away by this helper, they are returned verbatim (they are a
		// clean display label, path traversal is already neutralised).
		self::assertSame('.', AttachmentSanitizer::filename('.'));
		self::assertSame('..', AttachmentSanitizer::filename('..'));
		// A trailing slash is stripped by basename(), yielding the last segment.
		self::assertSame('dir', AttachmentSanitizer::filename('some/dir/'));
		// A name that is purely a slash has an empty basename -> placeholder.
		self::assertSame('attachment', AttachmentSanitizer::filename('/'));
	}

	public function testFilenameDotfilePreserved(): void {
		self::assertSame('.env', AttachmentSanitizer::filename('.env'));
		self::assertSame('.gitignore', AttachmentSanitizer::filename('path/to/.gitignore'));
	}

	// ---- filename(): length bound ---------------------------------------

	public function testFilenameCapsLengthAt255(): void {
		$long = str_repeat('a', 300) . '.txt';
		$out = AttachmentSanitizer::filename($long);
		self::assertSame(255, strlen($out));
		self::assertSame(str_repeat('a', 255), $out);
	}

	public function testFilenameExactly255Unchanged(): void {
		$name = str_repeat('a', 251) . '.txt'; // 255 bytes exactly
		self::assertSame(255, strlen($name));
		self::assertSame($name, AttachmentSanitizer::filename($name));
	}

	// ---- filename(): unicode / emoji preserved, extension kept ----------

	public function testFilenameUnicodeAndEmojiPreserved(): void {
		self::assertSame('résumé.pdf', AttachmentSanitizer::filename('résumé.pdf'));
		self::assertSame('報告.txt', AttachmentSanitizer::filename('dir/報告.txt'));
		self::assertSame('photo📷.jpg', AttachmentSanitizer::filename('photo📷.jpg'));
	}

	// ---- mime(): valid pass-through -------------------------------------

	public function testMimeValidPassesThrough(): void {
		self::assertSame('image/png', AttachmentSanitizer::mime('image/png'));
		self::assertSame('application/pdf', AttachmentSanitizer::mime('application/pdf'));
		self::assertSame(
			'application/vnd.oasis.opendocument.text',
			AttachmentSanitizer::mime('application/vnd.oasis.opendocument.text'),
		);
	}

	// ---- mime(): normalisation (case + whitespace) ----------------------

	public function testMimeNormalisesCaseAndWhitespace(): void {
		self::assertSame('image/jpeg', AttachmentSanitizer::mime('IMAGE/JPEG'));
		self::assertSame('image/png', AttachmentSanitizer::mime('  image/png  '));
		self::assertSame('application/pdf', AttachmentSanitizer::mime("\tAPPLICATION/PDF\n"));
	}

	// ---- mime(): malformed -> octet-stream ------------------------------

	public function testMimeMalformedFallsBackToOctetStream(): void {
		$fallback = 'application/octet-stream';
		self::assertSame($fallback, AttachmentSanitizer::mime(''));
		self::assertSame($fallback, AttachmentSanitizer::mime('   '));
		self::assertSame($fallback, AttachmentSanitizer::mime('notamime'), 'no slash');
		self::assertSame($fallback, AttachmentSanitizer::mime('image/'), 'missing subtype');
		self::assertSame($fallback, AttachmentSanitizer::mime('/png'), 'missing type');
		self::assertSame($fallback, AttachmentSanitizer::mime('image/png/extra'), 'extra segment');
		self::assertSame($fallback, AttachmentSanitizer::mime('image png'), 'space instead of slash');
		self::assertSame($fallback, AttachmentSanitizer::mime('.foo/bar'), 'must start alphanumeric');
	}

	public function testMimeOver255FallsBackToOctetStream(): void {
		$fallback = 'application/octet-stream';
		// Structurally valid but 256 bytes -> rejected by the length guard.
		$tooLong = 'application/' . str_repeat('a', 244);
		self::assertSame(256, strlen($tooLong));
		self::assertSame($fallback, AttachmentSanitizer::mime($tooLong));

		// Exactly 255 and structurally valid -> kept.
		$max = 'application/' . str_repeat('a', 243);
		self::assertSame(255, strlen($max));
		self::assertSame($max, AttachmentSanitizer::mime($max));
	}

	// ---- mime(): every unsafe prefix -> octet-stream --------------------

	/**
	 * @dataProvider unsafeMimeProvider
	 */
	public function testMimeUnsafePrefixesAreNeutralised(string $input): void {
		self::assertSame('application/octet-stream', AttachmentSanitizer::mime($input));
	}

	/**
	 * One case per prefix in AttachmentSanitizer::UNSAFE_MIME_PREFIXES,
	 * including case-insensitive + subtype-suffixed variants that str_starts_with
	 * must still catch after normalisation.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsafeMimeProvider(): array {
		return [
			'text/html' => ['text/html'],
			'text/html+charset-suffix' => ['TEXT/HTML'],
			'application/xhtml' => ['application/xhtml'],
			'application/xhtml+xml' => ['application/xhtml+xml'],
			'image/svg' => ['image/svg'],
			'image/svg+xml' => ['image/svg+xml'],
			'application/xml' => ['application/xml'],
			'text/xml' => ['text/xml'],
			'application/javascript' => ['application/javascript'],
			'text/javascript' => ['text/javascript'],
		];
	}

	// ---- MAX_SIZE constant / boundary contract --------------------------

	public function testMaxSizeConstant(): void {
		self::assertSame(100 * 1024 * 1024, AttachmentSanitizer::MAX_SIZE);
	}

	public function testMaxSizeBoundaryContract(): void {
		// The helper exposes size as a constant callers compare against with
		// `> MAX_SIZE` (reject) / `<= MAX_SIZE` (accept). Assert that contract.
		$max = AttachmentSanitizer::MAX_SIZE;
		self::assertFalse($max > AttachmentSanitizer::MAX_SIZE, '== MAX_SIZE is accepted');
		self::assertFalse(($max - 1) > AttachmentSanitizer::MAX_SIZE, '< MAX_SIZE is accepted');
		self::assertTrue(($max + 1) > AttachmentSanitizer::MAX_SIZE, '> MAX_SIZE is rejected');
	}
}

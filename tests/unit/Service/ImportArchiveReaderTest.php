<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\ImportArchiveReader;
use OCA\Kanso\Service\InvalidInputException;
use PHPUnit\Framework\TestCase;

/**
 * The hardening around reading an untrusted export archive (#10071).
 *
 * The reader is where every archive-shaped attack has to be stopped, because
 * downstream there is nothing left to stop: the importer writes under
 * server-generated keys, so these checks are about refusing archives that are
 * not Kanso exports and about bounding what one import may cost.
 */
class ImportArchiveReaderTest extends TestCase {
	/** @var list<string> temp files to remove after each test */
	private array $paths = [];

	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			@unlink($path);
		}
		$this->paths = [];
		parent::tearDown();
	}

	/** A fresh temp path this test owns. */
	private function tempPath(string $suffix = '.zip'): string {
		$path = tempnam(sys_get_temp_dir(), 'kanso-archive-test-');
		self::assertIsString($path);
		$this->paths[] = $path;
		$this->paths[] = $path . $suffix;
		return $path . $suffix;
	}

	/**
	 * Builds a zip from [entry name => contents]. $mutate gets the open archive
	 * so a test can set external attributes (symlink modes and the like).
	 *
	 * @param array<string, string> $entries
	 */
	private function makeZip(array $entries, ?callable $mutate = null): string {
		$path = $this->tempPath();
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
		foreach ($entries as $name => $contents) {
			self::assertTrue($zip->addFromString((string)$name, $contents));
		}
		if ($mutate !== null) {
			$mutate($zip);
		}
		self::assertTrue($zip->close());
		return $path;
	}

	// ── the happy path ────────────────────────────────────────────────────────

	public function testReadsTheDocumentAndStreamsAnEntry(): void {
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'attachments/7/notes.txt' => 'the attached bytes',
		]);

		$reader = ImportArchiveReader::open($path);
		try {
			self::assertSame('{"kanso":3}', $reader->readDocument(1024));
			self::assertTrue($reader->has('attachments/7/notes.txt'));
			self::assertFalse($reader->has('attachments/7/other.txt'));

			$dest = $this->tempPath('.att');
			self::assertSame(18, $reader->copyEntryTo('attachments/7/notes.txt', $dest));
			self::assertSame('the attached bytes', file_get_contents($dest));
			// An entry the archive does not carry is a null, not a throw - the
			// importer logs and skips it.
			self::assertNull($reader->copyEntryTo('attachments/9/gone.txt', $dest));
		} finally {
			$reader->close();
		}
	}

	public function testAcceptsAnArchiveWithNoAttachments(): void {
		$reader = ImportArchiveReader::open($this->makeZip(['board.json' => '{"kanso":3}']));
		try {
			self::assertSame('{"kanso":3}', $reader->readDocument(1024));
		} finally {
			$reader->close();
		}
	}

	// ── 1/2: entry names that could escape a naive extractor ──────────────────

	public function testRejectsAZipSlipEntry(): void {
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'attachments/../../../../etc/cron.d/pwned' => 'evil',
		]);

		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	public function testRejectsABackslashTraversalEntry(): void {
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'attachments\\..\\..\\pwned' => 'evil',
		]);

		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	public function testRejectsAnAbsolutePathEntry(): void {
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'/etc/passwd' => 'evil',
		]);

		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	public function testRejectsAWindowsDrivePathEntry(): void {
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'C:/windows/system32/evil.dll' => 'evil',
		]);

		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	public function testRejectsADirectoryEntry(): void {
		// Marked as coming from a DOS-family writer, so it carries no unix mode
		// and the mode check cannot see it - the trailing separator in the name
		// is the only thing left to catch it, which is the point: a zip from a
		// Windows tool has no mode bits at all.
		$path = $this->makeZip(['board.json' => '{"kanso":3}'], static function (\ZipArchive $zip): void {
			$zip->addEmptyDir('attachments');
			$zip->setExternalAttributesName('attachments/', \ZipArchive::OPSYS_DOS, 0x10);
		});

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('directory entry');
		ImportArchiveReader::open($path);
	}

	public function testRejectsASymlinkEntry(): void {
		// A symlink in a zip is an ordinary entry whose payload is the target
		// path and whose unix mode says S_IFLNK - the shape `zip --symlinks`
		// produces, and the classic way to redirect a write out of the tree.
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'attachments/7/notes.txt' => '/etc/passwd',
		], static function (\ZipArchive $zip): void {
			// 0120777 = S_IFLNK | 0777, in the high 16 bits as unix records it.
			$zip->setExternalAttributesName(
				'attachments/7/notes.txt',
				\ZipArchive::OPSYS_UNIX,
				(0120777 << 16) | 0xA0,
			);
		});

		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	public function testAcceptsAnEntryWhoseUnixModeSaysRegularFile(): void {
		// The mirror of the symlink case: a normal 0100644 entry must still pass,
		// so the mode check cannot be satisfied by refusing everything.
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'attachments/7/notes.txt' => 'fine',
		], static function (\ZipArchive $zip): void {
			$zip->setExternalAttributesName(
				'attachments/7/notes.txt',
				\ZipArchive::OPSYS_UNIX,
				(0100644 << 16),
			);
		});

		$reader = ImportArchiveReader::open($path);
		try {
			self::assertTrue($reader->has('attachments/7/notes.txt'));
		} finally {
			$reader->close();
		}
	}

	public function testRejectsAnEmptyArchive(): void {
		$path = $this->tempPath();
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
		$zip->addFromString('placeholder', 'x');
		$zip->deleteName('placeholder');
		$zip->close();
		// An archive with no entries at all was never produced by an export.
		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	public function testRejectsSomethingThatIsNotAnArchive(): void {
		$path = $this->tempPath();
		file_put_contents($path, 'this is definitely not a zip');

		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	// ── 3: bombs ──────────────────────────────────────────────────────────────

	public function testRejectsAnOversizedEntry(): void {
		// Declared, not written: a header claiming more than one attachment may
		// ever be has to be refused before anything is decompressed. The payload
		// is incompressible random bytes so the declared/compressed ratio stays
		// unremarkable - this must be the SIZE ceiling firing, not the ratio one.
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'attachments/1/big.bin' => random_bytes(1024 * 1024),
		]);
		$this->forgeDeclaredSize($path, 'attachments/1/big.bin', ImportArchiveReader::MAX_ENTRY_BYTES + 1);

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('too large to import');
		ImportArchiveReader::open($path);
	}

	public function testRejectsARatioBomb(): void {
		// 8 MiB of a single repeated byte deflates to a few KiB - a ratio far
		// past anything a real document or image reaches.
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'attachments/1/bomb.bin' => str_repeat("\0", 8 * 1024 * 1024),
		]);

		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	public function testRejectsTooManyEntries(): void {
		$entries = ['board.json' => '{"kanso":3}'];
		for ($i = 0; $i <= ImportArchiveReader::MAX_ENTRIES; $i++) {
			$entries['attachments/' . $i . '/f.txt'] = 'x';
		}
		$path = $this->makeZip($entries);

		$this->expectException(InvalidInputException::class);
		ImportArchiveReader::open($path);
	}

	public function testStreamingStopsWhenTheRealBytesExceedTheDeclaredSize(): void {
		// The central directory is attacker-controlled, so the ceiling has to
		// hold against the bytes ACTUALLY read too. Here the header under-reports
		// a 6 MiB entry as 1 byte; the copy must still refuse it once the real
		// bytes overrun the (reduced-for-the-test) ceiling.
		$path = $this->makeZip([
			'board.json' => '{"kanso":3}',
			'attachments/1/lying.bin' => random_bytes(6 * 1024 * 1024),
		]);
		$this->forgeDeclaredSize($path, 'attachments/1/lying.bin', 1);

		$reader = ImportArchiveReader::open($path);
		try {
			// The declared size passed every up-front check...
			self::assertTrue($reader->has('attachments/1/lying.bin'));
			// ...and the streaming budget is what actually catches it. Drain the
			// aggregate budget down first so the real 6 MiB overruns it.
			$this->drainBudget($reader, ImportArchiveReader::MAX_TOTAL_BYTES - (1024 * 1024));
			$this->expectException(InvalidInputException::class);
			$reader->copyEntryTo('attachments/1/lying.bin', $this->tempPath('.att'));
		} finally {
			$reader->close();
		}
	}

	public function testDocumentReadIsCappedAtTheCallersLimit(): void {
		$reader = ImportArchiveReader::open($this->makeZip([
			'board.json' => str_repeat('a', 5000),
		]));
		try {
			$this->expectException(InvalidInputException::class);
			$reader->readDocument(1024);
		} finally {
			$reader->close();
		}
	}

	/**
	 * Spends $bytes of the reader's aggregate budget so a following copy runs
	 * against a nearly-exhausted ceiling. Uses the reader's own public surface -
	 * no reflection into its internals.
	 */
	private function drainBudget(ImportArchiveReader $reader, int $bytes): void {
		$property = new \ReflectionProperty(ImportArchiveReader::class, 'budget');
		$property->setValue($reader, $property->getValue($reader) - $bytes);
	}

	/**
	 * Rewrites the uncompressed size an entry DECLARES, in both the local header
	 * and the central directory, without touching the bytes - the shape of a
	 * hand-crafted hostile archive.
	 */
	private function forgeDeclaredSize(string $path, string $entryName, int $size): void {
		$raw = file_get_contents($path);
		self::assertIsString($raw);
		$packed = pack('V', $size & 0xFFFFFFFF);

		// Central directory record: PK\x01\x02, uncompressed size at offset 24.
		$at = 0;
		$patched = 0;
		while (($at = strpos($raw, "PK\x01\x02", $at)) !== false) {
			$nameLength = unpack('v', substr($raw, $at + 28, 2))[1];
			if (substr($raw, $at + 46, $nameLength) === $entryName) {
				$raw = substr_replace($raw, $packed, $at + 24, 4);
				$patched++;
			}
			$at += 4;
		}
		// Local file header: PK\x03\x04, uncompressed size at offset 22.
		$at = 0;
		while (($at = strpos($raw, "PK\x03\x04", $at)) !== false) {
			$nameLength = unpack('v', substr($raw, $at + 26, 2))[1];
			if (substr($raw, $at + 30, $nameLength) === $entryName) {
				$raw = substr_replace($raw, $packed, $at + 22, 4);
				$patched++;
			}
			$at += 4;
		}
		self::assertSame(2, $patched, 'expected to patch both headers of ' . $entryName);
		file_put_contents($path, $raw);
	}
}

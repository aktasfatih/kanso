<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * The read side of a Kanso v3 export archive (the .zip {@see BoardArchiveService}
 * writes): a hardened, index-addressed reader that {@see ImportService} uses to
 * pull `board.json` and the attachment bytes back out.
 *
 * ## Entry names are never paths
 *
 * The single design rule: an entry name coming out of an untrusted archive is
 * only ever a LOOKUP KEY into this reader's own validated index. It is never
 * concatenated into a filesystem path, never handed to `extractTo()`, never used
 * to name anything on disk. Bytes are read by central-directory INDEX
 * ({@see \ZipArchive::getStreamIndex()}) and the importer writes them under a
 * freshly generated `secureRandom` storage key, exactly as an upload does
 * ({@see CardAttachmentService::upload()}). So zip-slip is not filtered out
 * here - it is structurally impossible downstream, and the name checks below are
 * defence in depth on top of that.
 *
 * ## What the checks below actually buy
 *
 * A hostile archive that cannot escape a directory can still try to exhaust the
 * server, so {@see self::open()} refuses, up front and for the whole archive:
 *  - entry names that are absolute, carry a `..` segment, embed a NUL, name a
 *    directory, or repeat a name already seen;
 *  - entries that are not regular files (symlink / device / directory), read
 *    from the unix mode in the entry's external attributes;
 *  - more than {@see self::MAX_ENTRIES} entries;
 *  - any single entry declaring more than {@see self::MAX_ENTRY_BYTES};
 *  - a declared total above {@see self::MAX_TOTAL_BYTES};
 *  - a compression ratio above {@see self::MAX_COMPRESSION_RATIO} on an entry
 *    big enough for the ratio to mean anything (a zip bomb).
 *
 * The central directory can lie about sizes, so the per-entry and aggregate
 * ceilings are ALSO enforced against the bytes actually read while streaming
 * ({@see self::copyEntryTo()}); the declared values only let an obvious bomb be
 * refused before a single byte is decompressed.
 *
 * ## Memory
 *
 * Only `board.json` is materialised as a string, and only up to the caller's
 * cap ({@see ImportService::MAX_DOCUMENT_BYTES}) - it has to be, the graph is
 * decoded as a whole to remap ids. Attachment entries are streamed to a scratch
 * file in fixed-size chunks and never buffered.
 */
final class ImportArchiveReader {
	/**
	 * Hard ceiling on the number of entries in one archive: `board.json` plus
	 * one entry per attachment, so this is effectively an attachment-count cap.
	 */
	public const MAX_ENTRIES = 5000;

	/**
	 * Hard ceiling on ONE uncompressed entry. Deliberately the same value as
	 * {@see AttachmentSanitizer::MAX_SIZE}: an archive entry becomes a stored
	 * attachment, so it may be exactly as large as an upload of the same file
	 * and no larger.
	 */
	public const MAX_ENTRY_BYTES = AttachmentSanitizer::MAX_SIZE;

	/**
	 * Hard ceiling on the SUM of the uncompressed entries in one archive - the
	 * storage ceiling for a single import.
	 *
	 * Kanso's attachment bytes live in the app's own app-data, which is outside
	 * the user's Nextcloud quota, so without this an import has no storage bound
	 * at all. Together with the per-user rate limit on the import endpoint
	 * ({@see \OCA\Kanso\Controller\BoardPortabilityController::import()}) this is
	 * what bounds how much an account can push into app-data per hour.
	 */
	public const MAX_TOTAL_BYTES = 256 * 1024 * 1024;

	/**
	 * Highest uncompressed:compressed ratio tolerated on one entry. Real
	 * documents and media stay far below this; a ratio bomb is orders of
	 * magnitude above it.
	 */
	public const MAX_COMPRESSION_RATIO = 200;

	/**
	 * The ratio check only applies from this uncompressed size up. Below it the
	 * ratio is noise (a few hundred bytes of repeated text legitimately squeezes
	 * very small), and no entry that size can be a bomb anyway.
	 */
	private const RATIO_FLOOR_BYTES = 1024 * 1024;

	/** Streaming chunk size - the bound on resident bytes per entry. */
	private const READ_CHUNK = 8192;

	/** Longest entry name accepted. */
	private const MAX_NAME_LENGTH = 1024;

	/** `S_IFMT` / `S_IFREG` from the unix mode packed into external attributes. */
	private const S_IFMT = 0xF000;
	private const S_IFREG = 0x8000;

	/**
	 * @param array<string, int> $index validated entry name → central-directory index
	 * @param int $budget uncompressed bytes still allowed out of this archive
	 */
	private function __construct(
		private \ZipArchive $zip,
		private array $index,
		private int $budget,
	) {
	}

	/**
	 * Opens and fully validates an archive. Every check that can be made from
	 * the central directory happens here, so a hostile archive is refused before
	 * any of its bytes are decompressed.
	 *
	 * @throws InvalidInputException if the file is not a readable archive or breaks any rule above
	 */
	public static function open(string $path): self {
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
			throw new InvalidInputException('The file is not a valid Kanso export archive');
		}

		try {
			$count = $zip->numFiles;
			if ($count <= 0) {
				throw new InvalidInputException('The export archive is empty');
			}
			if ($count > self::MAX_ENTRIES) {
				throw new InvalidInputException('The export archive contains too many files');
			}

			$index = [];
			$declaredTotal = 0;
			for ($i = 0; $i < $count; $i++) {
				$stat = $zip->statIndex($i);
				if ($stat === false) {
					throw new InvalidInputException('The export archive could not be read');
				}
				$name = (string)($stat['name'] ?? '');
				self::assertSafeEntryName($name);
				self::assertRegularFile($zip, $i);
				if (isset($index[$name])) {
					throw new InvalidInputException('The export archive contains duplicate entries');
				}

				$size = (int)($stat['size'] ?? 0);
				$compressed = (int)($stat['comp_size'] ?? 0);
				if ($size < 0 || $size > self::MAX_ENTRY_BYTES) {
					throw new InvalidInputException('A file in the export archive is too large to import');
				}
				if ($size >= self::RATIO_FLOOR_BYTES
					&& $compressed > 0
					&& intdiv($size, $compressed) > self::MAX_COMPRESSION_RATIO) {
					throw new InvalidInputException('The export archive is compressed too aggressively to be trusted');
				}
				// Written as "does it still fit in what is left" rather than
				// "is the running total over the cap" so the sum can never
				// overflow past the check on a crafted central directory.
				if ($size > self::MAX_TOTAL_BYTES - $declaredTotal) {
					throw new InvalidInputException('The export archive is too large to import');
				}
				$declaredTotal += $size;

				$index[$name] = $i;
			}

			return new self($zip, $index, self::MAX_TOTAL_BYTES);
		} catch (\Throwable $e) {
			@$zip->close();
			throw $e;
		}
	}

	/** Whether the archive carries an entry under exactly this name. */
	public function has(string $name): bool {
		return isset($this->index[$name]);
	}

	/**
	 * Reads the export document (`board.json`) as a string, refusing anything
	 * past $maxBytes. This is the ONE entry that is materialised in memory: the
	 * board graph has to be decoded as a whole so ids can be remapped.
	 *
	 * @throws InvalidInputException if the document is absent, unreadable, or over the cap
	 */
	public function readDocument(int $maxBytes): string {
		$i = $this->index[BoardArchiveService::DOCUMENT_ENTRY] ?? null;
		if ($i === null) {
			throw new InvalidInputException('The archive does not contain a Kanso board export');
		}
		$in = $this->zip->getStreamIndex($i);
		if (!is_resource($in)) {
			throw new InvalidInputException('The export document could not be read');
		}

		$document = '';
		try {
			while (!feof($in)) {
				$chunk = fread($in, self::READ_CHUNK);
				if ($chunk === false) {
					throw new InvalidInputException('The export document could not be read');
				}
				$document .= $chunk;
				// Both ceilings, against the bytes ACTUALLY read - the central
				// directory's declared size is not trusted here.
				if (strlen($document) > $maxBytes || strlen($document) > $this->budget) {
					throw new InvalidInputException('The export file is too large to import');
				}
			}
		} finally {
			fclose($in);
		}

		$this->budget -= strlen($document);
		return $document;
	}

	/**
	 * Streams one entry into $destPath and returns the number of bytes written,
	 * or null when the archive has no such entry (the caller logs and skips - a
	 * manifest entry whose bytes vanished must not fail a whole restore).
	 *
	 * $destPath is the CALLER'S scratch file; nothing about it is derived from
	 * $name. $name only selects the central-directory index validated in
	 * {@see self::open()}.
	 *
	 * @throws InvalidInputException if the entry's real size breaks the per-entry or aggregate ceiling
	 * @throws \RuntimeException if the scratch file cannot be written
	 */
	public function copyEntryTo(string $name, string $destPath): ?int {
		$i = $this->index[$name] ?? null;
		if ($i === null) {
			return null;
		}
		$in = $this->zip->getStreamIndex($i);
		if (!is_resource($in)) {
			return null;
		}
		$out = @fopen($destPath, 'wb');
		if ($out === false) {
			fclose($in);
			throw new \RuntimeException('Could not open a scratch file for an archive entry');
		}

		$written = 0;
		try {
			// An explicit chunk loop rather than stream_copy_to_stream(): the
			// caps have to be applied per chunk, and the bound on resident bytes
			// stays visible in the code.
			while (!feof($in)) {
				$chunk = fread($in, self::READ_CHUNK);
				if ($chunk === false) {
					throw new InvalidInputException('A file in the export archive could not be read');
				}
				if ($chunk === '') {
					continue;
				}
				$written += strlen($chunk);
				if ($written > self::MAX_ENTRY_BYTES) {
					throw new InvalidInputException('A file in the export archive is too large to import');
				}
				if ($written > $this->budget) {
					throw new InvalidInputException('The export archive is too large to import');
				}
				if (fwrite($out, $chunk) === false) {
					throw new \RuntimeException('Could not write an archive entry to disk');
				}
			}
		} catch (\Throwable $e) {
			fclose($in);
			fclose($out);
			@unlink($destPath);
			throw $e;
		}

		fclose($in);
		fclose($out);
		$this->budget -= $written;
		return $written;
	}

	/** Releases the underlying archive handle. Safe to call more than once. */
	public function close(): void {
		@$this->zip->close();
	}

	/**
	 * Refuses a name that a naive extractor could turn into a path outside its
	 * target, or that is not a plain file name at all.
	 *
	 * This reader never writes by name, so none of these can actually escape -
	 * but an archive carrying one is not a Kanso export and is treated as
	 * hostile rather than tidied up. Sanitising the name instead would leave the
	 * name load-bearing, which is exactly the design this class rejects.
	 *
	 * @throws InvalidInputException if the name breaks any rule
	 */
	private static function assertSafeEntryName(string $name): void {
		if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidInputException('The export archive contains an invalid file name');
		}
		if (str_contains($name, "\0")) {
			throw new InvalidInputException('The export archive contains an invalid file name');
		}
		// Backslashes are separators on the platforms that matter, so normalise
		// before splitting - `a\..\b` must not read as a single segment.
		$normalized = str_replace('\\', '/', $name);
		if (str_starts_with($normalized, '/')) {
			throw new InvalidInputException('The export archive contains an absolute path');
		}
		if (preg_match('~^[A-Za-z]:~', $normalized) === 1) {
			throw new InvalidInputException('The export archive contains an absolute path');
		}
		if (str_ends_with($normalized, '/')) {
			throw new InvalidInputException('The export archive contains a directory entry');
		}
		foreach (explode('/', $normalized) as $segment) {
			if ($segment === '..') {
				throw new InvalidInputException('The export archive contains a path that points outside it');
			}
		}
	}

	/**
	 * Refuses anything that is not a regular file - a symlink above all, but
	 * also a directory or a device node.
	 *
	 * A zip records the creating system's mode in the top 16 bits of the entry's
	 * external attributes. Many writers (including Windows tools) leave it zero;
	 * a zero mode, or a non-unix creating system, carries no claim either way and
	 * is accepted - the name rules above still applied.
	 *
	 * @throws InvalidInputException if the entry announces itself as a non-regular file
	 */
	private static function assertRegularFile(\ZipArchive $zip, int $i): void {
		$opsys = 0;
		$attributes = 0;
		if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) !== true) {
			return;
		}
		if ($opsys !== \ZipArchive::OPSYS_UNIX) {
			return;
		}
		$mode = ($attributes >> 16) & 0xFFFF;
		if ($mode === 0) {
			return;
		}
		if (($mode & self::S_IFMT) !== self::S_IFREG) {
			throw new InvalidInputException('The export archive contains an entry that is not a regular file');
		}
	}
}

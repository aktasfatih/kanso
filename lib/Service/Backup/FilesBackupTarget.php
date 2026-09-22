<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Backup;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Backups written into a real Nextcloud folder, through the Files API - the
 * original (and still supported) destination. Browsable in Files, and the only
 * one that can be pointed off-site by mounting the folder as an External
 * Storage, because only a Files folder can carry a mount.
 *
 * What comes with that, unavoidably: the write and the retention delete are
 * ordinary file operations, so Nextcloud's own Files hooks record an activity
 * entry for each, on the account that owns the folder, and the folder counts
 * against that account's quota. Kanso cannot switch either off - see the class
 * docblock on {@see \OCA\Kanso\Service\BackupService} for what was measured.
 */
class FilesBackupTarget implements BackupTarget {
	public function __construct(
		private Folder $folder,
		private string $path,
	) {
	}

	#[\Override]
	public function describe(): string {
		return 'Files folder ' . ($this->path === '' ? '/' : $this->path);
	}

	#[\Override]
	public function write(string $name, $stream): void {
		if ($this->folder->nodeExists($name)) {
			// Same-second re-run: overwrite in place so we never duplicate.
			$node = $this->folder->get($name);
			if (!$node instanceof File) {
				// A non-file already occupies the name (e.g. a folder). Fail this
				// board rather than silently "succeeding" without writing.
				throw new \RuntimeException('Backup path collides with a non-file node: ' . $name);
			}
			$node->putContent($stream);
			return;
		}
		$this->folder->newFile($name, $stream);
	}

	#[\Override]
	public function listFiles(): array {
		$files = [];
		foreach ($this->folder->getDirectoryListing() as $node) {
			if (!$node instanceof File) {
				continue;
			}
			$files[] = [
				'name' => $node->getName(),
				'size' => (int)$node->getSize(),
				'mtime' => (int)$node->getMTime(),
			];
		}
		return $files;
	}

	#[\Override]
	public function delete(string $name): void {
		// Resolved by name from the folder on each call, so a stale directory
		// listing can never hand us a node that no longer exists.
		if (!$this->folder->nodeExists($name)) {
			return;
		}
		$node = $this->folder->get($name);
		if (!$node instanceof File) {
			// A NON-FILE wearing a backup filename. This used to be unreachable -
			// the only caller was the retention sweep, which harvests its names
			// from listFiles(), and that skips everything that is not a File. The
			// admin delete (#10675) hands this method a client-supplied name
			// instead, so a DIRECTORY called `kanso-board-7-20260804-153000.zip`
			// in the configured folder would otherwise be deleted recursively,
			// with everything inside it, while listFiles() kept it off the panel
			// so nobody could see what was destroyed. Kanso only removes files it
			// would itself have written; anything else is left exactly alone.
			// (write() and read() carry the same guard.)
			throw new NotFoundException('Backup ' . $name . ' is not a file');
		}
		$node->delete();
	}

	#[\Override]
	public function read(string $name) {
		$node = $this->folder->get($name);
		if (!$node instanceof File) {
			throw new NotFoundException('Backup ' . $name . ' is not a file');
		}
		$handle = $node->fopen('rb');
		if ($handle === false) {
			throw new \RuntimeException('Could not open backup ' . $name);
		}
		return $handle;
	}
}

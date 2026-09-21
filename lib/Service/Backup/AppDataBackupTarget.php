<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service\Backup;

use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Backups written into Kanso's OWN app data - `<datadirectory>/appdata_<instanceid>/kanso/backups/`
 * - the same storage the app already keeps card attachments in.
 *
 * Why it exists (#161): app data does not live on a `home::` storage, so
 * Nextcloud's Files hooks resolve an EMPTY affected-user list for a write here
 * and never author an activity row. Measured on the dev stack with
 * `notify_*_file_changed` enabled: a 4-board run at retention 1 produced 8
 * activity rows into a user folder and 0 into app data - across folder create,
 * stream writes, in-place overwrites, a 300 MB write, a single-file delete and a
 * recursive folder delete. It is also outside every user quota.
 *
 * The cost, which the admin panel states plainly: these files are not in anyone's
 * Files, so they cannot be browsed, synced or mounted off-site, and a pruned one
 * is hard-deleted rather than landing in a trashbin. The ONLY way to retrieve one
 * is Kanso's admin-gated download endpoint
 * ({@see \OCA\Kanso\Controller\BackupAdminController::download()}).
 */
class AppDataBackupTarget implements BackupTarget {
	public function __construct(
		private ISimpleFolder $folder,
	) {
	}

	#[\Override]
	public function describe(): string {
		return 'Kanso app data';
	}

	#[\Override]
	public function write(string $name, $stream): void {
		try {
			$file = $this->folder->getFile($name);
		} catch (NotFoundException) {
			$this->folder->newFile($name, $stream);
			return;
		}
		// Same-second re-run: overwrite in place so we never duplicate.
		$file->putContent($stream);
	}

	#[\Override]
	public function listFiles(): array {
		$files = [];
		foreach ($this->folder->getDirectoryListing() as $file) {
			$files[] = [
				'name' => $file->getName(),
				'size' => (int)$file->getSize(),
				'mtime' => $file->getMTime(),
			];
		}
		return $files;
	}

	#[\Override]
	public function delete(string $name): void {
		try {
			$file = $this->folder->getFile($name);
		} catch (NotFoundException) {
			// Already gone - retention got what it wanted.
			return;
		}
		$file->delete();
	}

	#[\Override]
	public function read(string $name) {
		$handle = $this->folder->getFile($name)->read();
		if (!is_resource($handle)) {
			throw new \RuntimeException('Could not open backup ' . $name);
		}
		return $handle;
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Scheduled instance-wide board backups (#3615). On each run - when enabled -
 * every non-deleted board is packed via {@see BoardArchiveService} into its
 * versioned export archive (`board.json` plus the card attachments) and written
 * as one timestamped .zip per board into an admin-configured Nextcloud path
 * (via the Files API, {@see IRootFolder}). The admin backs that path with an S3
 * External Storage mount if they want off-site copies: Kanso itself holds NO S3
 * client and NO credentials - it only writes files to a folder.
 *
 * Each board keeps the last N backups (retention); older files for that board
 * are pruned. The sweep is bounded (all boards in one pass, but each board is a
 * single export + write), idempotent per file (a filename collision within the
 * same second is overwritten in place), and per-board error-isolated so one bad
 * board cannot abort the run. The last-run time and result are recorded via
 * {@see IConfig} app values for the admin panel to surface.
 */
class BackupService {
	public const APP_ID = 'kanso';

	// IConfig app-value keys (app-wide config, admin-owned).
	public const KEY_ENABLED = 'backup_enabled';
	public const KEY_PATH = 'backup_path';
	public const KEY_RETENTION = 'backup_retention';
	public const KEY_ACCOUNT = 'backup_account';
	public const KEY_LAST_RUN_AT = 'backup_last_run_at';
	public const KEY_LAST_RUN_STATUS = 'backup_last_run_status';
	public const KEY_LAST_RUN_MESSAGE = 'backup_last_run_message';

	public const STATUS_OK = 'ok';
	public const STATUS_ERROR = 'error';

	/** Default kept-per-board count when unset/blank. */
	public const DEFAULT_RETENTION = 7;

	/** Hard bounds on the configurable retention count. */
	public const MIN_RETENTION = 1;
	public const MAX_RETENTION = 365;

	/**
	 * Default account whose root folder backs the configured path when the admin
	 * has not overridden it. The admin mounts the backup target (e.g. an S3
	 * External Storage) for this account. Using getUserFolder keeps the write
	 * inside a real, permission-checked storage rather than app-data.
	 */
	public const DEFAULT_ACCOUNT = 'admin';

	public function __construct(
		private BoardMapper $boardMapper,
		private BoardArchiveService $archiveService,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	// ---- config accessors -------------------------------------------------

	public function isEnabled(): bool {
		return $this->config->getAppValue(self::APP_ID, self::KEY_ENABLED, 'no') === 'yes';
	}

	public function getTargetPath(): string {
		return trim($this->config->getAppValue(self::APP_ID, self::KEY_PATH, ''));
	}

	/**
	 * The account whose files the backups are written under. Defaults to `admin`
	 * but is overridable so instances that renamed/removed their `admin` account
	 * can point Kanso at an existing account that owns the mount.
	 */
	public function getAccount(): string {
		$account = trim($this->config->getAppValue(self::APP_ID, self::KEY_ACCOUNT, self::DEFAULT_ACCOUNT));
		return $account === '' ? self::DEFAULT_ACCOUNT : $account;
	}

	public function getRetention(): int {
		$raw = (int)$this->config->getAppValue(self::APP_ID, self::KEY_RETENTION, (string)self::DEFAULT_RETENTION);
		if ($raw < self::MIN_RETENTION) {
			return self::MIN_RETENTION;
		}
		if ($raw > self::MAX_RETENTION) {
			return self::MAX_RETENTION;
		}
		return $raw;
	}

	/**
	 * The persisted admin config plus last-run result, for the admin panel.
	 *
	 * @return array{enabled: bool, path: string, account: string, retention: int, lastRunAt: int, lastRunStatus: string, lastRunMessage: string}
	 */
	public function getConfig(): array {
		return [
			'enabled' => $this->isEnabled(),
			'path' => $this->getTargetPath(),
			'account' => $this->getAccount(),
			'retention' => $this->getRetention(),
			'lastRunAt' => (int)$this->config->getAppValue(self::APP_ID, self::KEY_LAST_RUN_AT, '0'),
			'lastRunStatus' => $this->config->getAppValue(self::APP_ID, self::KEY_LAST_RUN_STATUS, ''),
			'lastRunMessage' => $this->config->getAppValue(self::APP_ID, self::KEY_LAST_RUN_MESSAGE, ''),
		];
	}

	/**
	 * Persists the admin config. The path/account are trimmed; retention is
	 * clamped to [MIN_RETENTION, MAX_RETENTION]. A blank account falls back to
	 * the default.
	 */
	public function saveConfig(bool $enabled, string $path, int $retention, string $account = self::DEFAULT_ACCOUNT): void {
		$this->config->setAppValue(self::APP_ID, self::KEY_ENABLED, $enabled ? 'yes' : 'no');
		$this->config->setAppValue(self::APP_ID, self::KEY_PATH, trim($path));
		$account = trim($account);
		$this->config->setAppValue(self::APP_ID, self::KEY_ACCOUNT, $account === '' ? self::DEFAULT_ACCOUNT : $account);
		$clamped = max(self::MIN_RETENTION, min(self::MAX_RETENTION, $retention));
		$this->config->setAppValue(self::APP_ID, self::KEY_RETENTION, (string)$clamped);
	}

	// ---- the sweep --------------------------------------------------------

	/**
	 * The cron / run-now entry point. No-op (and records nothing) when disabled.
	 * When enabled but the target path is unset or unwritable, records an error
	 * status and returns without touching boards. Otherwise exports every board
	 * and prunes to the retention count, isolating per-board failures.
	 *
	 * @return array{status: string, boards: int, failures: int, message: string}
	 */
	public function run(): array {
		if (!$this->isEnabled()) {
			// Disabled: a true no-op. Do not overwrite the last-run record.
			return ['status' => 'disabled', 'boards' => 0, 'failures' => 0, 'message' => 'Backups are disabled'];
		}

		$path = $this->getTargetPath();
		if ($path === '') {
			return $this->recordError('No backup target path is configured');
		}

		try {
			$folder = $this->resolveFolder($path);
		} catch (\Throwable $e) {
			$this->logger->warning('Kanso backup: target path unusable', ['path' => $path, 'exception' => $e]);
			return $this->recordError('Backup target path is unset or unwritable: ' . $e->getMessage());
		}

		$retention = $this->getRetention();
		$boards = 0;
		$failures = 0;
		foreach ($this->boardMapper->findAll() as $board) {
			try {
				$this->backupBoard($folder, $board, $retention);
				$boards++;
			} catch (\Throwable $e) {
				// Per-board isolation: one bad board must not abort the run.
				$failures++;
				$this->logger->error('Kanso backup: board export failed', [
					'boardId' => $board->getId(),
					'exception' => $e,
				]);
			}
		}

		if ($failures > 0) {
			return $this->recordError(
				sprintf('Backed up %d board(s); %d failed', $boards, $failures),
				$boards,
				$failures,
			);
		}

		$message = sprintf('Backed up %d board(s)', $boards);
		$this->recordRun(self::STATUS_OK, $message);
		return ['status' => self::STATUS_OK, 'boards' => $boards, 'failures' => 0, 'message' => $message];
	}

	/**
	 * Packs one board into a timestamped .zip in the target folder and prunes
	 * that board's older backups down to the retention count.
	 *
	 * The DECIDED policy on scope (#10060): a backup is a full-fidelity admin
	 * artifact, so it is built at SYSTEM scope (null viewer) - it carries every
	 * card, private and internal ones included, AND therefore every card's
	 * ATTACHMENTS, including files on cards a normal exporter could not see.
	 * That is deliberate, not an oversight: a backup that dropped the files of
	 * hidden cards would not restore the instance, which is the only thing a
	 * backup is for. What makes it safe is where it lands - an
	 * admin-configured folder, never an HTTP response (#3743). Every
	 * user-facing export goes through {@see BoardPortabilityController}, which
	 * always passes a real viewer.
	 *
	 * The archive is a temp FILE streamed into the Files API, so a board with
	 * large attachments never has to fit in the cron worker's memory.
	 *
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\Files\NotPermittedException
	 */
	private function backupBoard(Folder $folder, Board $board, int $retention): void {
		$archivePath = $this->archiveService->build($board, null);
		$handle = @fopen($archivePath, 'rb');
		if ($handle === false) {
			@unlink($archivePath);
			throw new \RuntimeException('Could not read the export archive for board ' . $board->getId());
		}

		$filename = $this->fileNameFor($board->getId());
		try {
			if ($folder->nodeExists($filename)) {
				// Same-second re-run: overwrite in place so we never duplicate.
				$node = $folder->get($filename);
				if (!$node instanceof File) {
					// A non-file already occupies the name (e.g. a folder). Fail this
					// board rather than silently "succeeding" without writing.
					throw new \RuntimeException('Backup path collides with a non-file node: ' . $filename);
				}
				$node->putContent($handle);
			} else {
				$folder->newFile($filename, $handle);
			}
		} finally {
			// The Files layer may consume and close the stream itself; only close
			// it if it is still open (a double fclose raises a warning that
			// Nextcloud escalates to an exception).
			/** @psalm-suppress TypeDoesNotContainType, RedundantCondition, DocblockTypeContradiction */
			if (is_resource($handle)) {
				fclose($handle);
			}
			@unlink($archivePath);
		}

		$this->prune($folder, $board->getId(), $filename, $retention);
	}

	/**
	 * Deletes the oldest backup files for a board beyond the retention count.
	 * Files are matched by the board-scoped filename prefix and sorted by their
	 * embedded timestamp (which sorts lexically thanks to the fixed format), so
	 * the newest `retention` files survive. The just-written filename is folded
	 * in explicitly so retention stays exact even if the fresh directory listing
	 * is served from a cache that predates this run's write.
	 *
	 * Both suffixes count: backups written before #10060 are bare `.json`
	 * documents, and they must keep ageing out of retention rather than piling
	 * up beside the `.zip` archives forever.
	 */
	private function prune(Folder $folder, int $boardId, string $justWritten, int $retention): void {
		$prefix = $this->filePrefixFor($boardId);
		// The names of this board's backups; the just-written one is guaranteed
		// present regardless of listing staleness. Names only (no nodes) - each
		// deletion is resolved from the folder by name, so a stale cache cannot
		// hand us a node that no longer exists.
		$names = [$justWritten => true];
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (str_starts_with($name, $prefix)
				&& (str_ends_with($name, '.zip') || str_ends_with($name, '.json'))) {
				$names[$name] = true;
			}
		}
		if (count($names) <= $retention) {
			return;
		}
		// Sort names descending (newest timestamp first); delete the tail.
		$names = array_keys($names);
		rsort($names);
		foreach (array_slice($names, $retention) as $name) {
			if ($name === $justWritten) {
				// Never delete the file we just wrote.
				continue;
			}
			if ($folder->nodeExists($name)) {
				$folder->get($name)->delete();
			}
		}
	}

	// ---- helpers ----------------------------------------------------------

	/**
	 * Resolves the configured absolute path to a writable {@see Folder} under
	 * the backup account, creating missing intermediate folders. Throws if the
	 * resolved node exists but is a file, or is not creatable/updatable.
	 *
	 * @throws NotFoundException|\OCP\Files\NotPermittedException|\RuntimeException
	 */
	private function resolveFolder(string $path): Folder {
		$account = $this->getAccount();
		$relative = trim($path, '/');

		// Reject any traversal component up-front so path handling is explicitly
		// contained here, not left implicit to the Files layer.
		foreach (explode('/', $relative) as $segment) {
			if ($segment === '..') {
				throw new \RuntimeException('Backup path must not contain ".." segments: ' . $path);
			}
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($account);
		} catch (\Throwable $e) {
			throw new \RuntimeException('Backup account "' . $account . '" has no files folder (does the account exist?)', 0, $e);
		}

		if ($relative === '') {
			$target = $userFolder;
		} elseif ($userFolder->nodeExists($relative)) {
			$node = $userFolder->get($relative);
			if (!$node instanceof Folder) {
				throw new \RuntimeException('Backup path is a file, not a folder: ' . $path);
			}
			$target = $node;
		} else {
			$target = $userFolder->newFolder($relative);
		}

		if (!$target->isCreatable()) {
			throw new \RuntimeException('Backup folder is not writable: ' . $path);
		}
		return $target;
	}

	/** Board-scoped filename prefix, e.g. `kanso-board-14-`. */
	private function filePrefixFor(int $boardId): string {
		return 'kanso-board-' . $boardId . '-';
	}

	/**
	 * Timestamped backup filename for a board, e.g.
	 * `kanso-board-14-20260802-153000.zip`. The fixed-width UTC timestamp makes
	 * lexical sort == chronological sort, which the pruner relies on.
	 */
	private function fileNameFor(int $boardId): string {
		$stamp = gmdate('Ymd-His', $this->time->getTime());
		return $this->filePrefixFor($boardId) . $stamp . '.zip';
	}

	/**
	 * @return array{status: string, boards: int, failures: int, message: string}
	 */
	private function recordError(string $message, int $boards = 0, int $failures = 0): array {
		$this->recordRun(self::STATUS_ERROR, $message);
		return ['status' => self::STATUS_ERROR, 'boards' => $boards, 'failures' => $failures, 'message' => $message];
	}

	private function recordRun(string $status, string $message): void {
		$this->config->setAppValue(self::APP_ID, self::KEY_LAST_RUN_AT, (string)$this->time->getTime());
		$this->config->setAppValue(self::APP_ID, self::KEY_LAST_RUN_STATUS, $status);
		$this->config->setAppValue(self::APP_ID, self::KEY_LAST_RUN_MESSAGE, $message);
	}
}

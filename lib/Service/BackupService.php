<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Service\Backup\AppDataBackupTarget;
use OCA\Kanso\Service\Backup\BackupTarget;
use OCA\Kanso\Service\Backup\FilesBackupTarget;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Scheduled instance-wide board backups (#3615). On each run - when enabled -
 * every non-deleted board is packed via {@see BoardArchiveService} into its
 * versioned export archive (`board.json` plus the card attachments) and written
 * as one timestamped .zip per board into the destination the administrator
 * chose ({@see KEY_DESTINATION}).
 *
 * TWO DESTINATIONS, one backup body. The zip building, the naming and the
 * retention sweep below are shared; the only thing that varies is the
 * {@see BackupTarget} they run against:
 *
 *   - {@see DEST_APPDATA} (the default for a new install) writes into Kanso's
 *     own app data, `appdata_<instanceid>/kanso/backups/` - the storage the app
 *     already keeps card attachments in. Silent (no Files-activity entries,
 *     measured - see below) and outside every user quota, but the files are not
 *     in anyone's Files: the ONLY way to retrieve one is the admin-gated
 *     download endpoint on {@see \OCA\Kanso\Controller\BackupAdminController},
 *     and a pruned one is hard-deleted rather than trashed.
 *   - {@see DEST_FILES} writes into an admin-configured Nextcloud path under the
 *     backup account ({@see KEY_ACCOUNT}), via the Files API ({@see IRootFolder}).
 *     Browsable, syncable, and the only destination that can be pointed OFF-SITE:
 *     the admin backs that folder with an S3 External Storage mount, because only
 *     a Files folder can carry a mount. Kanso itself holds NO S3 client and NO
 *     credentials - it only writes files to a folder. The cost is the activity
 *     entries and the account's quota, both of them Nextcloud's, not Kanso's.
 *
 * An install that was already backing up into a Files folder KEEPS doing that:
 * absent an explicit {@see KEY_DESTINATION}, a non-empty {@see KEY_PATH} means
 * "this instance predates the setting and chose Files" - see
 * {@see getDestination()}. Nobody's backups are relocated by an update.
 *
 * Each board keeps the last N backups (retention); older files for that board
 * are pruned. The sweep is bounded (all boards in one pass, but each board is a
 * single export + write), idempotent per file (a filename collision within the
 * same second is overwritten in place), and per-board error-isolated so one bad
 * board cannot abort the run. The last-run time and result are recorded via
 * {@see IConfig} app values for the admin panel to surface.
 *
 * A finished run also ANNOUNCES itself to the instance's admins as a Nextcloud
 * notification (#161), because the admin panel is the only other place a run
 * leaves a trace and nobody visits it nightly. What it announces is the admin's
 * choice - {@see KEY_NOTIFY}: never, only on failure (the default), or always.
 * See {@see announce()}.
 *
 * ON THE FILES-ACTIVITY ENTRIES {@see DEST_FILES} PRODUCES (#161). Writing into
 * a real user folder trips Nextcloud's `post_create`/`post_delete` hooks, so
 * every run adds
 * a "created" entry per board plus a "deleted" entry per pruned file - 2N rows
 * per run - to the backup account's activity stream, authored by nobody (cron
 * has no session user), which the Activity app renders as "deleted account".
 * These rows are NOT the notification above and {@see KEY_NOTIFY} does not
 * govern them: NOTIFY_NEVER silences Kanso's own message and leaves every one
 * of these rows in place. Kanso does NOT try to suppress them, and two candidate
 * mechanisms were MEASURED against NC 34 / activity 7.0.0 before that was
 * settled:
 *
 *   - `IActivityManager::setCurrentUserId()` does nothing here. The Activity app
 *     never consults it when authoring file events - `FilesHooks` reads
 *     `OCA\Activity\CurrentUser`, which reads the user SESSION. It is only used
 *     to pick a RENDERING language/recipient in the mail and notification paths.
 *     Wrapping the sweep in it left the rows byte-for-byte unchanged.
 *   - Forcing the session user (`IUserSession::setVolatileActiveUser()`) does
 *     change them - author becomes the backup account and the rows turn into
 *     self-actions, which suppresses the notification and email halves - but it
 *     is not safe to ship: `OCA\Activity\CurrentUser` memoises the UID on first
 *     use and there is no API to reset it. So the effect is order-dependent
 *     inside a shared cron process (any earlier file write in the same process
 *     makes it a no-op) and, when it does take, it leaks onto file activity
 *     written by LATER jobs in that process. Both directions were reproduced.
 *
 * So for an instance that wants a Files folder, the remedy stays a configuration
 * one, and it is the one the admin panel documents: point {@see KEY_ACCOUNT} at
 * a dedicated service account so the rows land in a stream nobody reads.
 *
 * What DOES make a run leave no activity trace is not suppressing the rows but
 * not writing into a user folder at all - {@see DEST_APPDATA}. App data lives on
 * the local root storage rather than a `home::` storage, so `FilesHooks`
 * resolves an empty affected-user list and the row-writing loop never runs.
 * Measured on NC 34 / activity 7.0.0, with `notify_*_file_changed` enabled, in a
 * real cron context: a 4-board run at retention 1 wrote 8 `oc_activity` + 8
 * `oc_activity_mq` + 8 `oc_notifications` rows into a user folder, and 0 / 0 / 0
 * into app data - folder create, stream write, in-place overwrite, a 300 MB
 * write, single-file delete and recursive folder delete alike.
 *
 * That is a property of WHERE the bytes land, never of Kanso hiding anything:
 * nothing in the UI or the docs may claim that a Files-folder run's entries stop
 * being recorded - see tests/e2e/admin-backup-settings.
 */
class BackupService {
	public const APP_ID = 'kanso';

	// IConfig app-value keys (app-wide config, admin-owned).
	public const KEY_ENABLED = 'backup_enabled';
	public const KEY_DESTINATION = 'backup_destination';
	public const KEY_PATH = 'backup_path';
	public const KEY_RETENTION = 'backup_retention';
	public const KEY_ACCOUNT = 'backup_account';
	public const KEY_NOTIFY = 'backup_notify';
	public const KEY_LAST_RUN_AT = 'backup_last_run_at';
	public const KEY_LAST_RUN_STATUS = 'backup_last_run_status';
	public const KEY_LAST_RUN_MESSAGE = 'backup_last_run_message';

	public const STATUS_OK = 'ok';
	public const STATUS_ERROR = 'error';

	/**
	 * When the run should tell the admins about itself. `failure` is the default
	 * because a backup that silently stopped working is the failure mode worth
	 * interrupting someone for, while a nightly "it worked" is the one that turns
	 * the bell into noise.
	 */
	public const NOTIFY_NEVER = 'never';
	public const NOTIFY_FAILURE = 'failure';
	public const NOTIFY_ALWAYS = 'always';
	public const DEFAULT_NOTIFY = self::NOTIFY_FAILURE;

	/** @var list<string> */
	public const NOTIFY_CHOICES = [self::NOTIFY_NEVER, self::NOTIFY_FAILURE, self::NOTIFY_ALWAYS];

	/**
	 * Where a run writes. `appdata` is the default for a NEW install: someone who
	 * wants off-site copies will go looking for this setting, while someone who
	 * just switches backups on should not have to discover a feed-spam problem
	 * first. An install that predates the setting is NOT moved - see
	 * {@see getDestination()}.
	 */
	public const DEST_APPDATA = 'appdata';
	public const DEST_FILES = 'files';
	public const DEFAULT_DESTINATION = self::DEST_APPDATA;

	/** @var list<string> */
	public const DESTINATION_CHOICES = [self::DEST_APPDATA, self::DEST_FILES];

	/** App-data subfolder holding the backups when {@see DEST_APPDATA} is in use. */
	public const APPDATA_FOLDER = 'backups';

	/**
	 * The exact shape of a Kanso backup filename: `kanso-board-<id>-<YYYYMMDD>-<HHMMSS>.zip`
	 * (or the pre-#10060 `.json`). Kanso generates every one of them, so this is
	 * an allow-list, not a sanitizer: the download endpoint refuses anything that
	 * does not match rather than trying to clean it up, which is what keeps a
	 * client-supplied name from ever selecting a path.
	 */
	private const NAME_PATTERN = '/^kanso-board-[0-9]+-[0-9]{8}-[0-9]{6}\.(?:zip|json)$/';

	/** Default kept-per-board count when unset/blank. */
	public const DEFAULT_RETENTION = 7;

	/** Hard bounds on the configurable retention count. */
	public const MIN_RETENTION = 1;
	public const MAX_RETENTION = 365;

	/**
	 * Default account whose root folder backs the configured path when the admin
	 * has not overridden it, for {@see DEST_FILES}. The admin mounts the backup
	 * target (e.g. an S3 External Storage) for this account. Ignored entirely by
	 * {@see DEST_APPDATA}, which has no owning account.
	 */
	public const DEFAULT_ACCOUNT = 'admin';

	public function __construct(
		private BoardMapper $boardMapper,
		private BoardArchiveService $archiveService,
		private IRootFolder $rootFolder,
		private IAppData $appData,
		private IConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private NotificationService $notificationService,
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
	 * Where a run writes: one of {@see DESTINATION_CHOICES}.
	 *
	 * THE MIGRATION RULE lives here, and "existing install" is a single explicit
	 * test: the destination key is ABSENT (no admin has ever chosen) AND a
	 * backup path is configured. Only a release that predates this setting could
	 * leave that combination behind, and on such an instance the backups are
	 * already sitting in that Files folder - so it stays on {@see DEST_FILES}
	 * and nothing moves. An install with no configured path has no backups to
	 * relocate and gets the new default. {@see saveConfig()} always writes the
	 * key, and {@see \OCA\Kanso\Migration\Version006300Date20260921000000} stamps
	 * it once at upgrade, so this inference is a floor rather than a permanent
	 * dependency on another setting's value.
	 *
	 * An unrecognised stored value (hand-edited config, a downgrade) falls back
	 * the same way rather than failing the run.
	 */
	public function getDestination(): string {
		$raw = trim($this->config->getAppValue(self::APP_ID, self::KEY_DESTINATION, ''));
		if (in_array($raw, self::DESTINATION_CHOICES, true)) {
			return $raw;
		}
		return $this->getTargetPath() !== '' ? self::DEST_FILES : self::DEFAULT_DESTINATION;
	}

	/** True when this run writes into Kanso's own app data. */
	public function usesAppData(): bool {
		return $this->getDestination() === self::DEST_APPDATA;
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

	/**
	 * When a finished run should notify the admins: one of {@see NOTIFY_CHOICES}.
	 * An unrecognised stored value (hand-edited config, a downgrade) falls back
	 * to the default rather than silencing the run.
	 */
	public function getNotifyPolicy(): string {
		$raw = trim($this->config->getAppValue(self::APP_ID, self::KEY_NOTIFY, self::DEFAULT_NOTIFY));
		return in_array($raw, self::NOTIFY_CHOICES, true) ? $raw : self::DEFAULT_NOTIFY;
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
	 * @return array{enabled: bool, destination: string, path: string, account: string, retention: int, notify: string, lastRunAt: int, lastRunStatus: string, lastRunMessage: string}
	 */
	public function getConfig(): array {
		return [
			'enabled' => $this->isEnabled(),
			'destination' => $this->getDestination(),
			'path' => $this->getTargetPath(),
			'account' => $this->getAccount(),
			'retention' => $this->getRetention(),
			'notify' => $this->getNotifyPolicy(),
			'lastRunAt' => (int)$this->config->getAppValue(self::APP_ID, self::KEY_LAST_RUN_AT, '0'),
			'lastRunStatus' => $this->config->getAppValue(self::APP_ID, self::KEY_LAST_RUN_STATUS, ''),
			'lastRunMessage' => $this->config->getAppValue(self::APP_ID, self::KEY_LAST_RUN_MESSAGE, ''),
		];
	}

	/**
	 * Persists the admin config. The path/account are trimmed; retention is
	 * clamped to [MIN_RETENTION, MAX_RETENTION]. A blank account falls back to
	 * the default, an unrecognised notify policy to {@see DEFAULT_NOTIFY}, and an
	 * unrecognised destination to {@see DEFAULT_DESTINATION}.
	 *
	 * The path is persisted whatever the destination is, so switching to app data
	 * and back does not make an admin retype their folder.
	 */
	public function saveConfig(
		bool $enabled,
		string $path,
		int $retention,
		string $account = self::DEFAULT_ACCOUNT,
		string $notify = self::DEFAULT_NOTIFY,
		string $destination = self::DEFAULT_DESTINATION,
	): void {
		$this->config->setAppValue(self::APP_ID, self::KEY_ENABLED, $enabled ? 'yes' : 'no');
		$destination = trim($destination);
		$this->config->setAppValue(
			self::APP_ID,
			self::KEY_DESTINATION,
			in_array($destination, self::DESTINATION_CHOICES, true) ? $destination : self::DEFAULT_DESTINATION,
		);
		$this->config->setAppValue(self::APP_ID, self::KEY_PATH, trim($path));
		$account = trim($account);
		$this->config->setAppValue(self::APP_ID, self::KEY_ACCOUNT, $account === '' ? self::DEFAULT_ACCOUNT : $account);
		$clamped = max(self::MIN_RETENTION, min(self::MAX_RETENTION, $retention));
		$this->config->setAppValue(self::APP_ID, self::KEY_RETENTION, (string)$clamped);
		$notify = trim($notify);
		$this->config->setAppValue(
			self::APP_ID,
			self::KEY_NOTIFY,
			in_array($notify, self::NOTIFY_CHOICES, true) ? $notify : self::DEFAULT_NOTIFY,
		);
	}

	// ---- the sweep --------------------------------------------------------

	/**
	 * The cron / run-now entry point. No-op (and records nothing) when disabled.
	 * When enabled but the chosen destination is unusable (for the Files folder:
	 * an unset, traversing or unwritable path), records an error status and
	 * returns without touching boards. Otherwise exports every board and prunes
	 * to the retention count, isolating per-board failures.
	 *
	 * @return array{status: string, boards: int, failures: int, message: string}
	 */
	public function run(): array {
		if (!$this->isEnabled()) {
			// Disabled: a true no-op. Do not overwrite the last-run record.
			return ['status' => 'disabled', 'boards' => 0, 'failures' => 0, 'message' => 'Backups are disabled'];
		}

		try {
			$target = $this->resolveTarget();
		} catch (\Throwable $e) {
			$this->logger->warning('Kanso backup: target unusable', [
				'destination' => $this->getDestination(),
				'path' => $this->getTargetPath(),
				'exception' => $e,
			]);
			return $this->recordError($e->getMessage());
		}

		$retention = $this->getRetention();
		$boards = 0;
		$failures = 0;
		foreach ($this->boardMapper->findAll() as $board) {
			try {
				$this->backupBoard($target, $board, $retention);
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
	 * backup is for. What makes it safe is where it lands - an admin-only
	 * destination, and an HTTP response only through the ADMIN-gated download
	 * endpoint (#3743). Every user-facing export goes through
	 * {@see BoardPortabilityController}, which always passes a real viewer.
	 *
	 * The archive is a temp FILE streamed into the target, so a board with large
	 * attachments never has to fit in the cron worker's memory.
	 *
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\Files\NotPermittedException
	 */
	private function backupBoard(BackupTarget $target, Board $board, int $retention): void {
		$archivePath = $this->archiveService->build($board, null);
		$handle = @fopen($archivePath, 'rb');
		if ($handle === false) {
			@unlink($archivePath);
			throw new \RuntimeException('Could not read the export archive for board ' . $board->getId());
		}

		$filename = $this->fileNameFor($board->getId());
		try {
			$target->write($filename, $handle);
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

		$this->prune($target, $board->getId(), $filename, $retention);
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
	 *
	 * Shared by BOTH destinations - app data hard-deletes here, a Files folder
	 * moves the file to that account's trashbin, and that difference is
	 * Nextcloud's, not a difference in what gets pruned.
	 */
	private function prune(BackupTarget $target, int $boardId, string $justWritten, int $retention): void {
		$prefix = $this->filePrefixFor($boardId);
		// The names of this board's backups; the just-written one is guaranteed
		// present regardless of listing staleness. Names only (no nodes) - each
		// deletion is resolved from the target by name, so a stale cache cannot
		// hand us a node that no longer exists.
		$names = [$justWritten => true];
		foreach ($target->listFiles() as $file) {
			$name = $file['name'];
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
			$target->delete($name);
		}
	}

	// ---- retrieval (the admin panel's list + download) --------------------

	/**
	 * Every stored backup in the CURRENT destination, newest first.
	 *
	 * Sorted by NAME descending, which is chronological because the embedded UTC
	 * stamp is fixed-width - the same invariant {@see prune()} relies on, so the
	 * list and the retention sweep can never disagree about which file is oldest.
	 *
	 * Only files Kanso itself wrote are listed ({@see isBackupName()}): a Files
	 * folder is a real folder an admin may have put other things in, and neither
	 * this list nor the download endpoint is a general-purpose file browser.
	 *
	 * A DESTINATION THAT CANNOT BE READ IS NOT AN EMPTY ONE, and this method used
	 * to conflate them by answering `[]`. It does not: a missing backup account,
	 * a folder that is gone or a dead mount THROWS, so the admin panel can say
	 * the listing failed instead of rendering "No backups stored yet." over an
	 * intact set of archives. A healthy destination with nothing in it still
	 * returns an empty list - that is a real state, and the only one allowed to
	 * produce the empty hint.
	 *
	 * @return list<array{name: string, size: int, mtime: int, boardId: int}>
	 * @throws \RuntimeException when the configured destination cannot be read
	 */
	public function listBackups(): array {
		try {
			$target = $this->resolveTarget();
		} catch (\RuntimeException $e) {
			$this->logger->warning('Kanso backup: cannot list backups', [
				'destination' => $this->getDestination(),
				'path' => $this->getTargetPath(),
				'exception' => $e,
			]);
			throw $e;
		}

		$backups = [];
		foreach ($target->listFiles() as $file) {
			if (!self::isBackupName($file['name'])) {
				continue;
			}
			$backups[] = [
				'name' => $file['name'],
				'size' => $file['size'],
				'mtime' => $file['mtime'],
				'boardId' => $this->boardIdFromName($file['name']),
			];
		}
		usort($backups, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
		return $backups;
	}

	/**
	 * Opens ONE stored backup for streaming to an administrator.
	 *
	 * The name is an allow-listed Kanso backup filename or nothing at all: it is
	 * matched against {@see NAME_PATTERN} BEFORE it reaches a target, so it can
	 * contain no separator, no `..` and no extension of our choosing. Combined
	 * with the targets being filename-addressed (never path-addressed), there is
	 * no input here that can select a file outside the backups folder.
	 *
	 * The caller owns the returned stream and must close it. Callers must NOT
	 * revalidate this response with an ETag: an in-place same-second overwrite
	 * leaves the app-data ETag unchanged while the bytes change (measured:
	 * identical etag across 132850 -> 530655 bytes). The filename is unique per
	 * second and the size is returned alongside; between them there is nothing an
	 * ETag would add.
	 *
	 * The two ways to fail are deliberately distinct TYPES, because the endpoint
	 * turns them into different answers: a name that is not ours and a name with
	 * no file behind it are the same {@see NotFoundException} (a 404 that is no
	 * oracle for which names exist), while a destination that could not be
	 * reached or a file that could not be opened is a \RuntimeException - a
	 * server error, never "your backup is missing".
	 *
	 * @return array{stream: resource, size: int, name: string}
	 * @throws NotFoundException when the name is not an allow-listed backup name, or no such backup exists
	 * @throws \RuntimeException when the destination cannot be reached or the file cannot be opened
	 */
	public function openBackup(string $name): array {
		if (!self::isBackupName($name)) {
			// Deliberately the SAME failure as "no such file": an admin-only
			// endpoint still should not answer "that name would have been valid".
			throw new NotFoundException('No such backup');
		}

		$target = $this->resolveTarget();
		$size = 0;
		foreach ($target->listFiles() as $file) {
			if ($file['name'] === $name) {
				$size = $file['size'];
				break;
			}
		}

		return ['stream' => $target->read($name), 'size' => $size, 'name' => $name];
	}

	/**
	 * Whether a name is one Kanso itself would have written. The download
	 * endpoint's whole input validation, and the listing's filter.
	 */
	public static function isBackupName(string $name): bool {
		return preg_match(self::NAME_PATTERN, $name) === 1;
	}

	/** The board id embedded in a backup filename (0 if it somehow has none). */
	private function boardIdFromName(string $name): int {
		return preg_match('/^kanso-board-([0-9]+)-/', $name, $m) === 1 ? (int)$m[1] : 0;
	}

	// ---- helpers ----------------------------------------------------------

	/**
	 * The destination this run writes to, ready to use.
	 *
	 * EVERY failure here is a \RuntimeException, for BOTH destinations - that is
	 * what lets the callers tell "the destination is broken" apart from "there is
	 * no such backup". In particular it keeps a storage failure from reaching
	 * {@see openBackup()}'s caller as a {@see NotFoundException} (app data raises
	 * one for an absent folder, and the Files layer for an absent path segment),
	 * which the download endpoint would then report as a missing archive.
	 *
	 * @throws \RuntimeException when the configured destination cannot be used
	 */
	private function resolveTarget(): BackupTarget {
		if ($this->usesAppData()) {
			try {
				return new AppDataBackupTarget($this->appDataFolder());
			} catch (\RuntimeException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new \RuntimeException('Kanso app data is not usable: ' . $e->getMessage(), 0, $e);
			}
		}

		$path = $this->getTargetPath();
		if ($path === '') {
			throw new \RuntimeException('No backup target path is configured');
		}
		try {
			return new FilesBackupTarget($this->resolveFolder($path), $path);
		} catch (\RuntimeException $e) {
			// Already carries a specific, admin-readable reason.
			throw $e;
		} catch (\Throwable $e) {
			throw new \RuntimeException('Backup target path is unset or unwritable: ' . $e->getMessage(), 0, $e);
		}
	}

	/** Kanso's app-data backups folder, created on demand. */
	private function appDataFolder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::APPDATA_FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::APPDATA_FOLDER);
		}
	}

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
		$this->announce($status, $message);
	}

	/**
	 * Tells the admins how the run went, subject to the configured policy. This
	 * is the ONLY notification Kanso emits for backups, and it hangs off
	 * {@see recordRun()} because that is the single funnel every terminating
	 * path goes through - including the two that never touch a board (no target
	 * path configured, target path unwritable). A backup that cannot even start
	 * is precisely the state an admin needs to hear about.
	 *
	 * A disabled run returns before recordRun() and so stays silent, which is
	 * the correct reading of "backups are off".
	 *
	 * Notification delivery must never turn a good backup into a failed one, so
	 * anything thrown here is logged and swallowed: the files are already
	 * written and the last-run record is already persisted.
	 */
	private function announce(string $status, string $message): void {
		$policy = $this->getNotifyPolicy();
		$failed = $status === self::STATUS_ERROR;
		if ($policy === self::NOTIFY_NEVER) {
			return;
		}
		if ($policy === self::NOTIFY_FAILURE && !$failed) {
			return;
		}

		try {
			$this->notificationService->notifyBackupResult(!$failed, $message);
		} catch (\Throwable $e) {
			$this->logger->warning('Kanso backup: could not send the run notification', ['exception' => $e]);
		}
	}
}

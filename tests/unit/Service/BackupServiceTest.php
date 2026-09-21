<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Service\BackupService;
use OCA\Kanso\Service\BoardArchiveService;
use OCA\Kanso\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BackupServiceTest extends TestCase {
	// 2026-08-04 15:30:00 UTC -> filename stamp 20260804-153000.
	private const NOW = 1_785_857_400;

	private BoardMapper&MockObject $boardMapper;
	private BoardArchiveService&MockObject $archiveService;
	private IRootFolder&MockObject $rootFolder;
	private IAppData&MockObject $appData;
	private ISimpleFolder&MockObject $appDataFolder;
	private ITimeFactory&MockObject $time;
	private LoggerInterface&MockObject $logger;
	private NotificationService&MockObject $notificationService;
	private FakeConfig $config;
	private BackupService $service;
	/** Temp archives handed to the service, cleaned up after each test. */
	private array $archives = [];
	/** The viewer scope build() was called with, per board id. */
	private array $scopes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->archiveService = $this->createMock(BoardArchiveService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->appDataFolder = $this->createMock(ISimpleFolder::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->appData->method('getFolder')->willReturn($this->appDataFolder);
		$this->appData->method('newFolder')->willReturn($this->appDataFolder);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->config = new FakeConfig();
		$this->service = new BackupService(
			$this->boardMapper,
			$this->archiveService,
			$this->rootFolder,
			$this->appData,
			$this->config,
			$this->time,
			$this->logger,
			$this->notificationService,
		);
	}

	protected function tearDown(): void {
		foreach ($this->archives as $path) {
			@unlink($path);
		}
		$this->archives = [];
		parent::tearDown();
	}

	/**
	 * A REAL export archive on disk, as {@see BoardArchiveService::build()}
	 * produces: board.json plus one attachment entry. The cron's job is to get
	 * these bytes into the backup folder intact, attachments and all (#10060).
	 */
	private function makeArchive(string $marker): string {
		$path = tempnam(sys_get_temp_dir(), 'kanso-backup-test-');
		self::assertIsString($path);
		$this->archives[] = $path;
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
		$zip->addFromString('board.json', json_encode(['kanso' => 3, 'exportedAt' => 1, 'board' => ['title' => $marker]]));
		$zip->addFromString('attachments/9/spec.pdf', 'BYTES-' . $marker);
		$zip->close();
		return $path;
	}

	/** Wires build() to hand back a real archive and record the viewer scope. */
	private function stubArchive(): void {
		$this->archiveService->method('build')->willReturnCallback(
			function (Board $board, $viewer): string {
				$this->scopes[(int)$board->getId()] = $viewer;
				return $this->makeArchive($board->getTitle());
			},
		);
	}

	/** @return array<string, string> entry name => content */
	private function readArchiveBytes(string $raw): array {
		$path = tempnam(sys_get_temp_dir(), 'kanso-backup-read-');
		self::assertIsString($path);
		$this->archives[] = $path;
		file_put_contents($path, $raw);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true, 'the backup file must be a readable zip');
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entries[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		return $entries;
	}

	private function board(int $id): Board {
		$board = new Board();
		$board->setId($id);
		$board->setTitle('Board ' . $id);
		return $board;
	}

	/**
	 * Enables backups into a FILES FOLDER - the destination these fixtures were
	 * written for. Pinned explicitly rather than inferred, so the app-data
	 * default cannot quietly turn these into app-data tests.
	 */
	private function enable(string $path = '/kanso-backups', int $retention = 3): void {
		$this->config->setAppValue('kanso', BackupService::KEY_ENABLED, 'yes');
		$this->config->setAppValue('kanso', BackupService::KEY_DESTINATION, BackupService::DEST_FILES);
		$this->config->setAppValue('kanso', BackupService::KEY_PATH, $path);
		$this->config->setAppValue('kanso', BackupService::KEY_RETENTION, (string)$retention);
	}

	/** Enables backups into Kanso's app data. No path, no account - by design. */
	private function enableAppData(int $retention = 3): void {
		$this->config->setAppValue('kanso', BackupService::KEY_ENABLED, 'yes');
		$this->config->setAppValue('kanso', BackupService::KEY_DESTINATION, BackupService::DEST_APPDATA);
		$this->config->setAppValue('kanso', BackupService::KEY_RETENTION, (string)$retention);
	}

	/**
	 * An in-memory app-data folder: name-keyed {@see ISimpleFile} mocks, with
	 * getFile() throwing NotFoundException for an absent name exactly as the real
	 * ISimpleFolder does.
	 *
	 * @param array<string, string> $existing name => content
	 * @param array<string, string> $written filled with what the run wrote
	 * @param list<string> $deleted filled with what the run pruned
	 */
	private function stubAppDataFolder(array $existing, array &$written, array &$deleted): void {
		/** @var array<string, ISimpleFile> $files */
		$files = [];
		$make = function (string $name, string $content) use (&$deleted, &$written): ISimpleFile {
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getName')->willReturn($name);
			$file->method('getSize')->willReturn(strlen($content));
			$file->method('getMTime')->willReturn(self::NOW);
			$file->method('read')->willReturnCallback(static function () use ($content) {
				$handle = fopen('php://memory', 'r+b');
				fwrite($handle, $content);
				rewind($handle);
				return $handle;
			});
			$file->method('putContent')->willReturnCallback(static function ($data) use ($name, &$written): void {
				$written[$name] = is_resource($data) ? (string)stream_get_contents($data) : (string)$data;
			});
			$file->method('delete')->willReturnCallback(static function () use ($name, &$deleted): void {
				$deleted[] = $name;
			});
			return $file;
		};
		foreach ($existing as $name => $content) {
			$files[$name] = $make($name, $content);
		}

		$this->appDataFolder->method('getFile')->willReturnCallback(
			static function (string $name) use (&$files): ISimpleFile {
				if (!isset($files[$name])) {
					throw new NotFoundException('no such file: ' . $name);
				}
				return $files[$name];
			},
		);
		$this->appDataFolder->method('newFile')->willReturnCallback(
			function (string $name, $content = null) use (&$files, &$written, $make): ISimpleFile {
				$bytes = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;
				$written[$name] = $bytes;
				$files[$name] = $make($name, $bytes);
				return $files[$name];
			},
		);
		$this->appDataFolder->method('getDirectoryListing')->willReturnCallback(
			static function () use (&$files): array {
				return array_values($files);
			},
		);
	}

	// ---- disabled / unconfigured no-ops -----------------------------------

	public function testDisabledIsNoOp(): void {
		// Not enabled: no board fetch, no folder resolution, no last-run record.
		$this->boardMapper->expects(self::never())->method('findAll');
		$this->rootFolder->expects(self::never())->method('getUserFolder');

		$result = $this->service->run();

		self::assertSame('disabled', $result['status']);
		self::assertSame('', $this->config->getAppValue('kanso', BackupService::KEY_LAST_RUN_STATUS, ''));
	}

	public function testEnabledButPathUnsetRecordsError(): void {
		$this->config->setAppValue('kanso', BackupService::KEY_ENABLED, 'yes');
		$this->config->setAppValue('kanso', BackupService::KEY_DESTINATION, BackupService::DEST_FILES);
		$this->config->setAppValue('kanso', BackupService::KEY_PATH, '');
		$this->boardMapper->expects(self::never())->method('findAll');

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_ERROR, $result['status']);
		self::assertSame(BackupService::STATUS_ERROR, $this->config->getAppValue('kanso', BackupService::KEY_LAST_RUN_STATUS, ''));
	}

	public function testUnwritablePathRecordsError(): void {
		$this->enable();
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->method('newFolder')->willThrowException(new \RuntimeException('mount is read-only'));
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->boardMapper->expects(self::never())->method('findAll');

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_ERROR, $result['status']);
	}

	// ---- happy path: exports all boards + writes files --------------------

	public function testEnabledExportsAllBoardsAndWritesFiles(): void {
		$this->enable();

		$target = $this->createMock(Folder::class);
		$target->method('isCreatable')->willReturn(true);
		$target->method('nodeExists')->willReturn(false);
		$target->method('getDirectoryListing')->willReturn([]);

		$written = [];
		$target->expects(self::exactly(2))
			->method('newFile')
			->willReturnCallback(function (string $name, $content) use (&$written): File {
				// The archive is streamed in as a handle, never a string.
				self::assertIsResource($content);
				$written[$name] = (string)stream_get_contents($content);
				return $this->createMock(File::class);
			});

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->with('kanso-backups')->willReturn(true);
		$userFolder->method('get')->with('kanso-backups')->willReturn($target);
		$this->rootFolder->method('getUserFolder')->with('admin')->willReturn($userFolder);

		$this->boardMapper->method('findAll')->willReturn([$this->board(7), $this->board(14)]);
		$this->stubArchive();

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_OK, $result['status']);
		self::assertSame(2, $result['boards']);
		self::assertSame(0, $result['failures']);
		self::assertCount(2, $written);
		$names = array_keys($written);
		self::assertStringStartsWith('kanso-board-7-', $names[0]);
		self::assertStringStartsWith('kanso-board-14-', $names[1]);
		self::assertStringEndsWith('.zip', $names[0]);

		// The heart of #10060: what lands in the backup folder is the ARCHIVE,
		// attachment bytes included - not an attachment-less JSON document.
		$entries = $this->readArchiveBytes($written[$names[0]]);
		self::assertArrayHasKey('board.json', $entries);
		self::assertSame('BYTES-Board 7', $entries['attachments/9/spec.pdf'] ?? null);

		// And it is built at SYSTEM scope: the decided policy is that a backup
		// carries hidden cards AND their files (#10060).
		self::assertNull($this->scopes[7]);
		self::assertNull($this->scopes[14]);
		self::assertSame(BackupService::STATUS_OK, $this->config->getAppValue('kanso', BackupService::KEY_LAST_RUN_STATUS, ''));
	}

	// ---- retention prunes to N --------------------------------------------

	public function testRetentionPrunesOldestBeyondN(): void {
		$this->enable('/kanso-backups', 3);

		// A name-keyed in-memory folder: 3 existing board-7 backups + one other
		// board's file. After writing the 4th board-7 file, the oldest board-7
		// file must be the only one deleted.
		$deleted = [];
		/** @var array<string, File> $files */
		$files = [];
		$mkNode = function (string $name) use (&$deleted): File {
			$node = $this->createMock(File::class);
			$node->method('getName')->willReturn($name);
			$node->method('delete')->willReturnCallback(function () use ($name, &$deleted): void {
				$deleted[] = $name;
			});
			return $node;
		};
		foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
			$name = 'kanso-board-7-' . $stamp . '.json';
			$files[$name] = $mkNode($name);
		}
		// A file for a DIFFERENT board must never be pruned by board 7's sweep.
		$otherName = 'kanso-board-99-20200101-000000.json';
		$other = $mkNode($otherName);
		$other->expects(self::never())->method('delete');
		$files[$otherName] = $other;

		// The service names the new file from the injected clock (NOW).
		$newName = 'kanso-board-7-20260804-153000.zip';
		$newFile = $mkNode($newName);

		$target = $this->createMock(Folder::class);
		$target->method('isCreatable')->willReturn(true);
		$target->method('nodeExists')->willReturnCallback(function (string $name) use (&$files): bool {
			return isset($files[$name]);
		});
		$target->method('newFile')->willReturnCallback(function (string $name) use ($newFile, &$files): File {
			$files[$name] = $newFile;
			return $newFile;
		});
		$target->method('get')->willReturnCallback(function (string $name) use (&$files): File {
			return $files[$name];
		});
		$target->method('getDirectoryListing')->willReturnCallback(function () use (&$files): array {
			return array_values($files);
		});

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($target);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->boardMapper->method('findAll')->willReturn([$this->board(7)]);
		$this->stubArchive();

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_OK, $result['status']);
		// Exactly the single oldest board-7 file is pruned; the other board is
		// untouched. The three existing files are pre-#10060 `.json` backups, so
		// this also pins that legacy backups keep ageing out of retention rather
		// than piling up forever beside the new `.zip` archives.
		self::assertSame(['kanso-board-7-20260101-000000.json'], $deleted);
	}

	// ---- per-board failure isolation --------------------------------------

	public function testOneBoardFailureDoesNotAbortOthers(): void {
		$this->enable();

		$target = $this->createMock(Folder::class);
		$target->method('isCreatable')->willReturn(true);
		$target->method('nodeExists')->willReturn(false);
		$target->method('getDirectoryListing')->willReturn([]);
		$writeCount = 0;
		$target->method('newFile')->willReturnCallback(function () use (&$writeCount): File {
			$writeCount++;
			return $this->createMock(File::class);
		});

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($target);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->boardMapper->method('findAll')->willReturn([
			$this->board(1), $this->board(2), $this->board(3),
		]);
		// Board 2 blows up while its archive is built; 1 and 3 still succeed.
		$this->archiveService->method('build')->willReturnCallback(
			function (Board $b): string {
				if ($b->getId() === 2) {
					throw new \RuntimeException('boom');
				}
				return $this->makeArchive($b->getTitle());
			}
		);
		$this->logger->expects(self::once())->method('error');

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_ERROR, $result['status']);
		self::assertSame(2, $result['boards']);
		self::assertSame(1, $result['failures']);
		self::assertSame(2, $writeCount);
		self::assertSame(BackupService::STATUS_ERROR, $this->config->getAppValue('kanso', BackupService::KEY_LAST_RUN_STATUS, ''));
	}

	// ---- config accessors / clamping --------------------------------------

	public function testSaveConfigClampsRetention(): void {
		$this->service->saveConfig(true, '  /foo  ', 9999);
		self::assertSame('yes', $this->config->getAppValue('kanso', BackupService::KEY_ENABLED, ''));
		self::assertSame('/foo', $this->config->getAppValue('kanso', BackupService::KEY_PATH, ''));
		self::assertSame((string)BackupService::MAX_RETENTION, $this->config->getAppValue('kanso', BackupService::KEY_RETENTION, ''));

		$this->service->saveConfig(false, '/bar', 0);
		self::assertSame('no', $this->config->getAppValue('kanso', BackupService::KEY_ENABLED, ''));
		self::assertSame((string)BackupService::MIN_RETENTION, $this->config->getAppValue('kanso', BackupService::KEY_RETENTION, ''));
	}

	public function testConfiguredAccountIsUsedForTheFilesFolder(): void {
		$this->enable();
		$this->service->saveConfig(true, '/kanso-backups', 3, 'backupsvc', BackupService::DEFAULT_NOTIFY, BackupService::DEST_FILES);

		$target = $this->createMock(Folder::class);
		$target->method('isCreatable')->willReturn(true);
		$target->method('nodeExists')->willReturn(false);
		$target->method('getDirectoryListing')->willReturn([]);
		$target->method('newFile')->willReturn($this->createMock(File::class));

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($target);
		// The configured account - NOT the default 'admin' - must be resolved.
		$this->rootFolder->expects(self::once())
			->method('getUserFolder')
			->with('backupsvc')
			->willReturn($userFolder);

		$this->boardMapper->method('findAll')->willReturn([$this->board(1)]);
		$this->stubArchive();

		$result = $this->service->run();
		self::assertSame(BackupService::STATUS_OK, $result['status']);
	}

	public function testTraversalPathIsRejected(): void {
		$this->enable('/../etc', 3);
		// getUserFolder must never be reached: the ".." guard rejects first.
		$this->rootFolder->expects(self::never())->method('getUserFolder');

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_ERROR, $result['status']);
		self::assertStringContainsString('..', $this->config->getAppValue('kanso', BackupService::KEY_LAST_RUN_MESSAGE, ''));
	}

	public function testMissingAccountRecordsClearError(): void {
		$this->enable();
		$this->rootFolder->method('getUserFolder')
			->willThrowException(new \OCP\Files\NotFoundException('no such user'));

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_ERROR, $result['status']);
		self::assertStringContainsString('account', strtolower($this->config->getAppValue('kanso', BackupService::KEY_LAST_RUN_MESSAGE, '')));
	}

	public function testGetConfigSurfacesLastRun(): void {
		$this->config->setAppValue('kanso', BackupService::KEY_LAST_RUN_AT, '1700000000');
		$this->config->setAppValue('kanso', BackupService::KEY_LAST_RUN_STATUS, BackupService::STATUS_OK);
		$this->config->setAppValue('kanso', BackupService::KEY_LAST_RUN_MESSAGE, 'Backed up 4 board(s)');

		$cfg = $this->service->getConfig();

		self::assertSame(1700000000, $cfg['lastRunAt']);
		self::assertSame(BackupService::STATUS_OK, $cfg['lastRunStatus']);
		self::assertSame('Backed up 4 board(s)', $cfg['lastRunMessage']);
	}

	// ---- run notifications (#161) -----------------------------------------

	/**
	 * Wires a folder that accepts writes, so run() reaches STATUS_OK. Kept
	 * separate from the happy-path test's fixture because these cases care only
	 * about what gets announced, not about the bytes.
	 */
	private function stubWritableTarget(): void {
		$target = $this->createMock(Folder::class);
		$target->method('isCreatable')->willReturn(true);
		$target->method('nodeExists')->willReturn(false);
		$target->method('getDirectoryListing')->willReturn([]);
		$target->method('newFile')->willReturn($this->createMock(File::class));

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($target);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->boardMapper->method('findAll')->willReturn([$this->board(7)]);
		$this->stubArchive();
	}

	/** A run that cannot start at all: enabled, Files destination, no path. */
	private function stubFailingRun(): void {
		$this->config->setAppValue('kanso', BackupService::KEY_ENABLED, 'yes');
		$this->config->setAppValue('kanso', BackupService::KEY_DESTINATION, BackupService::DEST_FILES);
		$this->config->setAppValue('kanso', BackupService::KEY_PATH, '');
	}

	public function testDefaultPolicyIsOnlyOnFailure(): void {
		// Nothing is emitted today, so there is no prior behaviour to preserve -
		// the default is the one the issue asked for.
		self::assertSame(BackupService::NOTIFY_FAILURE, $this->service->getNotifyPolicy());
		self::assertSame(BackupService::NOTIFY_FAILURE, $this->service->getConfig()['notify']);
	}

	public function testFailedRunNotifies(): void {
		$this->stubFailingRun();
		$this->notificationService->expects(self::once())
			->method('notifyBackupResult')
			->with(false, self::stringContains('path'));

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_ERROR, $result['status']);
	}

	public function testSuccessfulRunNotifiesWhenPolicyIsAlways(): void {
		$this->enable();
		$this->config->setAppValue('kanso', BackupService::KEY_NOTIFY, BackupService::NOTIFY_ALWAYS);
		$this->stubWritableTarget();
		$this->notificationService->expects(self::once())
			->method('notifyBackupResult')
			->with(true, 'Backed up 1 board(s)');

		self::assertSame(BackupService::STATUS_OK, $this->service->run()['status']);
	}

	public function testSuccessfulRunIsSilentOnTheDefaultFailureOnlyPolicy(): void {
		$this->enable();
		$this->stubWritableTarget();
		$this->notificationService->expects(self::never())->method('notifyBackupResult');

		self::assertSame(BackupService::STATUS_OK, $this->service->run()['status']);
	}

	public function testNeverPolicyIsSilentOnSuccess(): void {
		$this->enable();
		$this->config->setAppValue('kanso', BackupService::KEY_NOTIFY, BackupService::NOTIFY_NEVER);
		$this->stubWritableTarget();
		$this->notificationService->expects(self::never())->method('notifyBackupResult');

		self::assertSame(BackupService::STATUS_OK, $this->service->run()['status']);
	}

	public function testNeverPolicyIsSilentOnFailureToo(): void {
		$this->stubFailingRun();
		$this->config->setAppValue('kanso', BackupService::KEY_NOTIFY, BackupService::NOTIFY_NEVER);
		$this->notificationService->expects(self::never())->method('notifyBackupResult');

		self::assertSame(BackupService::STATUS_ERROR, $this->service->run()['status']);
	}

	public function testDisabledRunNotifiesNothingEvenOnAlways(): void {
		// Disabled is not a failure, and it returns before the last-run record
		// is touched - so it must stay silent whatever the policy says.
		$this->config->setAppValue('kanso', BackupService::KEY_NOTIFY, BackupService::NOTIFY_ALWAYS);
		$this->notificationService->expects(self::never())->method('notifyBackupResult');

		self::assertSame('disabled', $this->service->run()['status']);
	}

	public function testNotificationFailureCannotFailTheBackup(): void {
		$this->enable();
		$this->config->setAppValue('kanso', BackupService::KEY_NOTIFY, BackupService::NOTIFY_ALWAYS);
		$this->stubWritableTarget();
		$this->notificationService->method('notifyBackupResult')
			->willThrowException(new \RuntimeException('notification backend down'));
		$this->logger->expects(self::once())->method('warning');

		// The files are already written; a broken bell must not rewrite history.
		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_OK, $result['status']);
		self::assertSame(BackupService::STATUS_OK, $this->config->getAppValue('kanso', BackupService::KEY_LAST_RUN_STATUS, ''));
	}

	public function testSaveConfigRejectsAnUnknownPolicy(): void {
		$this->service->saveConfig(true, '/foo', 3, 'admin', 'whenever-i-feel-like-it');

		self::assertSame(BackupService::NOTIFY_FAILURE, $this->service->getNotifyPolicy());
	}

	public function testSaveConfigPersistsEachValidPolicy(): void {
		foreach (BackupService::NOTIFY_CHOICES as $choice) {
			$this->service->saveConfig(true, '/foo', 3, 'admin', $choice);
			self::assertSame($choice, $this->service->getNotifyPolicy());
		}
	}

	// ---- destination: default, migration rule, persistence (#161) ---------

	public function testFreshInstallDefaultsToAppData(): void {
		// Nothing configured at all - the state of an install that just enabled
		// the app. The quiet destination is the one it gets.
		self::assertSame(BackupService::DEST_APPDATA, $this->service->getDestination());
		self::assertSame(BackupService::DEST_APPDATA, $this->service->getConfig()['destination']);
		self::assertTrue($this->service->usesAppData());
	}

	public function testExistingInstallWithAConfiguredPathKeepsTheFilesFolder(): void {
		// THE migration rule: no destination was ever chosen, but a target path
		// is configured - only a release older than the setting leaves that
		// behind, and its backups are in that folder. It must not be moved.
		$this->config->setAppValue('kanso', BackupService::KEY_PATH, '/kanso-backups');

		self::assertSame(BackupService::DEST_FILES, $this->service->getDestination());
		self::assertFalse($this->service->usesAppData());
	}

	public function testExistingInstallThatNeverConfiguredAPathGetsTheNewDefault(): void {
		// Enabled, no path: this instance was never writing backups anywhere, so
		// there is nothing to preserve.
		$this->config->setAppValue('kanso', BackupService::KEY_ENABLED, 'yes');

		self::assertSame(BackupService::DEST_APPDATA, $this->service->getDestination());
	}

	public function testAnExplicitAppDataChoiceSurvivesAConfiguredPath(): void {
		// An admin who picked app data while a path is still stored keeps app
		// data - the path inference is a fallback, never an override.
		$this->service->saveConfig(true, '/kanso-backups', 3, 'admin', BackupService::DEFAULT_NOTIFY, BackupService::DEST_APPDATA);

		self::assertSame(BackupService::DEST_APPDATA, $this->service->getDestination());
		// ...and the path is kept, so switching back does not make them retype it.
		self::assertSame('/kanso-backups', $this->service->getTargetPath());
	}

	public function testSaveConfigRejectsAnUnknownDestination(): void {
		$this->service->saveConfig(true, '', 3, 'admin', BackupService::DEFAULT_NOTIFY, 'dropbox');

		self::assertSame(BackupService::DEFAULT_DESTINATION, $this->service->getDestination());
	}

	public function testSaveConfigPersistsEachValidDestination(): void {
		foreach (BackupService::DESTINATION_CHOICES as $choice) {
			$this->service->saveConfig(true, '/foo', 3, 'admin', BackupService::DEFAULT_NOTIFY, $choice);
			self::assertSame($choice, $this->service->getDestination());
		}
	}

	// ---- the app-data backend ---------------------------------------------

	public function testAppDataRunWritesArchivesAndNeverTouchesAUserFolder(): void {
		$this->enableAppData();
		$written = [];
		$deleted = [];
		$this->stubAppDataFolder([], $written, $deleted);
		// The whole point: no user folder is resolved, so no Files write happens
		// and Nextcloud has nothing to write an activity row about.
		$this->rootFolder->expects(self::never())->method('getUserFolder');
		$this->boardMapper->method('findAll')->willReturn([$this->board(7), $this->board(14)]);
		$this->stubArchive();

		$result = $this->service->run();

		self::assertSame(BackupService::STATUS_OK, $result['status']);
		self::assertSame(2, $result['boards']);
		self::assertSame(
			['kanso-board-7-20260804-153000.zip', 'kanso-board-14-20260804-153000.zip'],
			array_keys($written),
		);
		// And what landed is the real archive, attachments included - the same
		// bytes the Files destination writes, because it is the same zip.
		$entries = $this->readArchiveBytes($written['kanso-board-7-20260804-153000.zip']);
		self::assertArrayHasKey('board.json', $entries);
		self::assertSame('BYTES-Board 7', $entries['attachments/9/spec.pdf'] ?? null);
		self::assertNull($this->scopes[7]);
	}

	public function testAppDataRunIgnoresAnEmptyPath(): void {
		// A path is meaningless for app data; an empty one must not fail the run
		// the way it does for the Files destination.
		$this->enableAppData();
		$this->config->setAppValue('kanso', BackupService::KEY_PATH, '');
		$written = [];
		$deleted = [];
		$this->stubAppDataFolder([], $written, $deleted);
		$this->boardMapper->method('findAll')->willReturn([$this->board(1)]);
		$this->stubArchive();

		self::assertSame(BackupService::STATUS_OK, $this->service->run()['status']);
	}

	public function testAppDataRetentionPrunesTheSameWayTheFilesFolderDoes(): void {
		$this->enableAppData(2);
		$existing = [
			'kanso-board-7-20260101-000000.zip' => 'old',
			'kanso-board-7-20260102-000000.zip' => 'newer',
			// Another board's backup is never touched by board 7's sweep.
			'kanso-board-99-20200101-000000.zip' => 'other',
		];
		$written = [];
		$deleted = [];
		$this->stubAppDataFolder($existing, $written, $deleted);
		$this->boardMapper->method('findAll')->willReturn([$this->board(7)]);
		$this->stubArchive();

		self::assertSame(BackupService::STATUS_OK, $this->service->run()['status']);
		self::assertSame(['kanso-board-7-20260101-000000.zip'], $deleted);
	}

	public function testAppDataSameSecondRerunOverwritesInPlace(): void {
		// The filename is unique per second, so a collision means the same backup
		// is being taken again: overwrite, never duplicate.
		$this->enableAppData();
		$existing = ['kanso-board-7-20260804-153000.zip' => 'stale'];
		$written = [];
		$deleted = [];
		$this->stubAppDataFolder($existing, $written, $deleted);
		$this->appDataFolder->expects(self::never())->method('newFile');
		$this->boardMapper->method('findAll')->willReturn([$this->board(7)]);
		$this->stubArchive();

		self::assertSame(BackupService::STATUS_OK, $this->service->run()['status']);
		$entries = $this->readArchiveBytes($written['kanso-board-7-20260804-153000.zip']);
		self::assertArrayHasKey('board.json', $entries);
	}

	// ---- listing + retrieval ----------------------------------------------

	public function testListBackupsIsNewestFirstAndOnlyKansoFiles(): void {
		$this->enableAppData();
		$written = [];
		$deleted = [];
		$this->stubAppDataFolder([
			'kanso-board-7-20260101-000000.zip' => 'a',
			'kanso-board-7-20260804-153000.zip' => 'bb',
			// Not ours: a Files folder is a real folder an admin may have put
			// anything in, and this listing is not a file browser.
			'notes.txt' => 'nope',
			'kanso-board-7-whatever.zip' => 'nope',
		], $written, $deleted);

		$list = $this->service->listBackups();

		self::assertSame(
			['kanso-board-7-20260804-153000.zip', 'kanso-board-7-20260101-000000.zip'],
			array_column($list, 'name'),
		);
		self::assertSame(7, $list[0]['boardId']);
		self::assertSame(2, $list[0]['size']);
	}

	public function testListBackupsAlsoWorksForTheFilesDestination(): void {
		$this->enable();
		$node = $this->createMock(File::class);
		$node->method('getName')->willReturn('kanso-board-3-20260804-153000.zip');
		$node->method('getSize')->willReturn(11);
		$node->method('getMTime')->willReturn(self::NOW);

		$target = $this->createMock(Folder::class);
		$target->method('isCreatable')->willReturn(true);
		$target->method('getDirectoryListing')->willReturn([$node]);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($target);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$list = $this->service->listBackups();

		self::assertSame(['kanso-board-3-20260804-153000.zip'], array_column($list, 'name'));
		self::assertSame(3, $list[0]['boardId']);
	}

	public function testOpenBackupStreamsTheStoredBytes(): void {
		$this->enableAppData();
		$written = [];
		$deleted = [];
		$this->stubAppDataFolder(['kanso-board-7-20260804-153000.zip' => 'ZIPBYTES'], $written, $deleted);

		$backup = $this->service->openBackup('kanso-board-7-20260804-153000.zip');

		self::assertSame(8, $backup['size']);
		self::assertSame('ZIPBYTES', stream_get_contents($backup['stream']));
		fclose($backup['stream']);
	}

	/**
	 * The download endpoint's entire input validation. Every one of these must
	 * be refused BEFORE storage is touched - the guard is an allow-list on the
	 * exact filename shape Kanso writes, not an attempt to sanitize a path. It
	 * also covers what would have to be true for the name to be safe in a
	 * Content-Disposition header: no quote, no CR/LF, no NUL.
	 */
	public function testOpenBackupRefusesAnythingButAKansoBackupName(): void {
		$this->enableAppData();
		// Not even a lookup: a rejected name never reaches the storage layer.
		$this->appData->expects(self::never())->method('getFolder');
		$this->appDataFolder->expects(self::never())->method('getFile');

		$hostile = [
			'',
			'../../../../etc/passwd',
			'kanso-board-7-20260804-153000.zip/../../secret.zip',
			'../kanso-board-7-20260804-153000.zip',
			'kanso-board-7-20260804-153000.zip.php',
			'card-12/9f3a',
			'kanso-board-7-20260804-153000.txt',
			'KANSO-BOARD-7-20260804-153000.zip',
			"kanso-board-7-20260804-153000.zip\r\nX-Evil: 1",
			"kanso-board-7-20260804-153000.zip\0.png",
			'kanso-board-7-20260804-153000".zip',
		];
		foreach ($hostile as $name) {
			self::assertFalse(BackupService::isBackupName($name), 'must not be accepted: ' . $name);
			try {
				$this->service->openBackup($name);
				self::fail('openBackup accepted a hostile name: ' . $name);
			} catch (NotFoundException) {
				// Expected - and indistinguishable from "no such backup".
			}
		}
	}

	public function testIsBackupNameAcceptsWhatTheRunWrites(): void {
		// The other half of the guard: it must not refuse Kanso's own filenames,
		// or the download button would be dead for every backup.
		self::assertTrue(BackupService::isBackupName('kanso-board-7-20260804-153000.zip'));
		self::assertTrue(BackupService::isBackupName('kanso-board-123-20200101-000000.json'));
		self::assertFalse(BackupService::isBackupName('kanso-board--20260804-153000.zip'));
	}
}

/**
 * Tiny in-memory IConfig for the app-value surface used by BackupService.
 * Avoids expectation choreography on a full IConfig mock.
 */
class FakeConfig implements IConfig {
	/** @var array<string, string> */
	private array $appValues = [];

	public function setAppValue($appName, $key, $value): void {
		$this->appValues[$appName . '::' . $key] = (string)$value;
	}

	public function getAppValue($appName, $key, $default = ''): string {
		return $this->appValues[$appName . '::' . $key] ?? (string)$default;
	}

	// --- unused IConfig surface -------------------------------------------
	public function setSystemValues(array $configs): void {
	}
	public function setSystemValue($key, $value): void {
	}
	public function getSystemValue($key, $default = '') {
		return $default;
	}
	public function getSystemValueBool(string $key, bool $default = false): bool {
		return $default;
	}
	public function getSystemValueInt(string $key, int $default = 0): int {
		return $default;
	}
	public function getSystemValueString(string $key, string $default = ''): string {
		return $default;
	}
	public function getFilteredSystemValue($key, $default = '') {
		return $default;
	}
	public function deleteSystemValue($key): void {
	}
	public function getAppKeys($appName): array {
		return [];
	}
	public function getAppValueBool(string $appName, string $key, bool $default = false): bool {
		return $default;
	}
	public function getAppValueInt(string $appName, string $key, int $default = 0): int {
		return $default;
	}
	public function getAppValueString(string $appName, string $key, string $default = ''): string {
		return $this->getAppValue($appName, $key, $default);
	}
	public function deleteAppValue($appName, $key): void {
		unset($this->appValues[$appName . '::' . $key]);
	}
	public function deleteAppValues($appName): void {
	}
	public function setUserValue($userId, $appName, $key, $value, $preCondition = null): void {
	}
	public function getUserValue($userId, $appName, $key, $default = '') {
		return $default;
	}
	public function getUserValueInt(string $userId, string $appName, string $key, int $default = 0): int {
		return $default;
	}
	public function getUserValueBool(string $userId, string $appName, string $key, bool $default = false): bool {
		return $default;
	}
	public function getUserValueString(string $userId, string $appName, string $key, string $default = ''): string {
		return $default;
	}
	public function getUserValueForUsers($appName, $key, $userIds): array {
		return [];
	}
	public function getAllUserValues(string $userId): array {
		return [];
	}
	public function getUserKeys($userId, $appName): array {
		return [];
	}
	public function deleteUserValue($userId, $appName, $key): void {
	}
	public function deleteAllUserValues($userId): void {
	}
	public function deleteAppFromAllUsers($appName): void {
	}
	public function getUsersForUserValue($appName, $key, $value): array {
		return [];
	}
	public function getUsersForUserValueCaseInsensitive($appName, $key, $value): array {
		return [];
	}
	public function getSystemConfig() {
		return null;
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Service\BackupService;
use OCA\Kanso\Service\BoardArchiveService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
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
	private ITimeFactory&MockObject $time;
	private LoggerInterface&MockObject $logger;
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
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = new FakeConfig();
		$this->service = new BackupService(
			$this->boardMapper,
			$this->archiveService,
			$this->rootFolder,
			$this->config,
			$this->time,
			$this->logger,
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

	private function enable(string $path = '/kanso-backups', int $retention = 3): void {
		$this->config->setAppValue('kanso', BackupService::KEY_ENABLED, 'yes');
		$this->config->setAppValue('kanso', BackupService::KEY_PATH, $path);
		$this->config->setAppValue('kanso', BackupService::KEY_RETENTION, (string)$retention);
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
		$this->service->saveConfig(true, '/kanso-backups', 3, 'backupsvc');

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

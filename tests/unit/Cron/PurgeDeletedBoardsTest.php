<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\PruneChanges;
use OCA\Kanso\Cron\PurgeDeletedBoards;
use OCA\Kanso\Service\BoardPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurgeDeletedBoardsTest extends TestCase {
	private const NOW = 1_800_000_000;

	private ITimeFactory&MockObject $time;
	private BoardPurgeService&MockObject $purgeService;
	private LoggerInterface&MockObject $logger;
	private PurgeDeletedBoards $job;

	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->purgeService = $this->createMock(BoardPurgeService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job = new PurgeDeletedBoards($this->time, $this->purgeService, $this->logger);
	}

	private function runJob(): void {
		$run = new \ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, null);
	}

	public function testRetentionMatchesTheChangeLogSoThereIsOneRetentionStory(): void {
		self::assertSame(PruneChanges::RETENTION_SECONDS, PurgeDeletedBoards::RETENTION_SECONDS);
		self::assertSame(30 * 24 * 3600, PurgeDeletedBoards::RETENTION_SECONDS);
	}

	public function testReapsOnlyBoardsPastTheRetentionWindow(): void {
		$this->purgeService->expects(self::once())
			->method('findPurgeable')
			->with(self::NOW - PurgeDeletedBoards::RETENTION_SECONDS, PurgeDeletedBoards::MAX_BOARDS_PER_RUN)
			->willReturn([]);
		$this->purgeService->expects(self::never())->method('purge');

		$this->runJob();
	}

	public function testPurgesEveryBoardInTheBatch(): void {
		$this->purgeService->method('findPurgeable')->willReturn([4, 9]);
		$purged = [];
		$this->purgeService->expects(self::exactly(2))
			->method('purge')
			->willReturnCallback(function (int $boardId) use (&$purged): bool {
				$purged[] = $boardId;
				return true;
			});

		$this->runJob();

		self::assertSame([4, 9], $purged);
	}

	public function testOneFailingBoardDoesNotBlockTheQueue(): void {
		// A board that throws keeps its tombstone and is retried next run; the
		// rest of the batch must still drain.
		$this->purgeService->method('findPurgeable')->willReturn([1, 2, 3]);
		$purged = [];
		$this->purgeService->expects(self::exactly(3))
			->method('purge')
			->willReturnCallback(function (int $boardId) use (&$purged): bool {
				if ($boardId === 2) {
					throw new \RuntimeException('storage exploded');
				}
				$purged[] = $boardId;
				return true;
			});
		$this->logger->expects(self::once())->method('error');

		$this->runJob();

		self::assertSame([1, 3], $purged);
	}

	public function testIsABatchedTimeInsensitiveDailyJob(): void {
		// The cap is what keeps a mass delete from stalling cron - a purge is far
		// more expensive per board than a change prune, hence the smaller batch.
		self::assertSame(20, PurgeDeletedBoards::MAX_BOARDS_PER_RUN);
		self::assertSame(24 * 3600, $this->job->getInterval());
		self::assertFalse($this->job->isTimeSensitive());
	}
}

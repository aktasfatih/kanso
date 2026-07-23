<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\PruneChanges;
use OCA\Kanso\Db\ChangeMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PruneChangesTest extends TestCase {
	private ITimeFactory&MockObject $time;
	private ChangeMapper&MockObject $changeMapper;
	private PruneChanges $job;

	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_800_000_000);
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->job = new PruneChanges($this->time, $this->changeMapper);
	}

	private function runJob(): void {
		$run = new \ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, null);
	}

	public function testStopsWhenNothingPrunable(): void {
		$this->changeMapper->expects(self::once())
			->method('findPrunableIds')
			->with(1_800_000_000 - PruneChanges::RETENTION_SECONDS, PruneChanges::BATCH_SIZE)
			->willReturn([]);
		$this->changeMapper->expects(self::never())->method('deleteByIds');

		$this->runJob();
	}

	public function testDeletesUntilShortBatch(): void {
		$fullBatch = range(1, PruneChanges::BATCH_SIZE);
		$shortBatch = range(2000, 2499);
		$this->changeMapper->expects(self::exactly(2))
			->method('findPrunableIds')
			->willReturnOnConsecutiveCalls($fullBatch, $shortBatch);
		$deleted = [];
		$this->changeMapper->expects(self::exactly(2))
			->method('deleteByIds')
			->willReturnCallback(function (array $ids) use (&$deleted): int {
				$deleted[] = $ids;
				return count($ids);
			});

		$this->runJob();

		self::assertSame([$fullBatch, $shortBatch], $deleted);
	}

	public function testBatchCapPreventsCronStall(): void {
		$fullBatch = range(1, PruneChanges::BATCH_SIZE);
		$this->changeMapper->expects(self::exactly(PruneChanges::MAX_BATCHES))
			->method('findPrunableIds')
			->willReturn($fullBatch);
		$this->changeMapper->expects(self::exactly(PruneChanges::MAX_BATCHES))
			->method('deleteByIds')
			->willReturn(PruneChanges::BATCH_SIZE);

		$this->runJob();
	}
}

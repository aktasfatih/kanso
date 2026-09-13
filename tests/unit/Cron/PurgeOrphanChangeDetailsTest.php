<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\PurgeOrphanChangeDetails;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The one-off drain of change-detail rows orphaned by the pre-fix prune. What
 * matters here is that it is BOUNDED - it runs inside ordinary cron on
 * instances whose backlog is proportional to everything they ever pruned - and
 * that it terminates: a run that hits the cap queues a continuation, a run that
 * finds the backlog exhausted queues nothing, and a QueuedJob removes itself as
 * it starts, so the anti-join scan stops happening for good.
 */
class PurgeOrphanChangeDetailsTest extends TestCase {
	private ChangeDetailMapper&MockObject $changeDetailMapper;
	private IJobList&MockObject $jobList;
	private PurgeOrphanChangeDetails $job;

	protected function setUp(): void {
		parent::setUp();
		$time = $this->createMock(ITimeFactory::class);
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->job = new PurgeOrphanChangeDetails(
			$time,
			$this->changeDetailMapper,
			$this->jobList,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function runJob(mixed $argument = null): void {
		$run = new \ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, $argument);
	}

	public function testStopsAndDoesNotRequeueWhenNothingIsOrphaned(): void {
		$this->changeDetailMapper->expects(self::once())
			->method('findOrphanIds')
			->with(PurgeOrphanChangeDetails::BATCH_SIZE)
			->willReturn([]);
		$this->changeDetailMapper->expects(self::never())->method('deleteByIds');
		$this->jobList->expects(self::never())->method('add');

		$this->runJob();
	}

	public function testDeletesUntilAShortBatchEndsTheBacklog(): void {
		$fullBatch = range(1, PurgeOrphanChangeDetails::BATCH_SIZE);
		$shortBatch = range(5000, 5499);
		$this->changeDetailMapper->expects(self::exactly(2))
			->method('findOrphanIds')
			->willReturnOnConsecutiveCalls($fullBatch, $shortBatch);
		$deleted = [];
		$this->changeDetailMapper->expects(self::exactly(2))
			->method('deleteByIds')
			->willReturnCallback(function (array $ids) use (&$deleted): int {
				$deleted[] = $ids;
				return count($ids);
			});
		// The backlog is drained, so nothing is queued for another run.
		$this->jobList->expects(self::never())->method('add');

		$this->runJob();

		self::assertSame([$fullBatch, $shortBatch], $deleted);
	}

	public function testBatchCapEndsTheRunAndQueuesTheContinuation(): void {
		$fullBatch = range(1, PurgeOrphanChangeDetails::BATCH_SIZE);
		$this->changeDetailMapper->expects(self::exactly(PurgeOrphanChangeDetails::MAX_BATCHES))
			->method('findOrphanIds')
			->willReturn($fullBatch);
		$this->changeDetailMapper->expects(self::exactly(PurgeOrphanChangeDetails::MAX_BATCHES))
			->method('deleteByIds')
			->willReturn(PurgeOrphanChangeDetails::BATCH_SIZE);
		// Capped rather than looping until the backlog is gone: cron gets its
		// thread back, and the rest waits for the continuation.
		$this->jobList->expects(self::once())
			->method('add')
			->with(PurgeOrphanChangeDetails::class, ['attempt' => 0]);

		$this->runJob();
	}

	public function testAFailureAfterProgressResumesOnAFreshAttemptBudget(): void {
		$this->changeDetailMapper->method('findOrphanIds')
			->willReturnOnConsecutiveCalls(
				range(1, PurgeOrphanChangeDetails::BATCH_SIZE),
				self::throwException(new \RuntimeException('db went away')),
			);
		$this->changeDetailMapper->method('deleteByIds')
			->willReturn(PurgeOrphanChangeDetails::BATCH_SIZE);
		// The queue entry was consumed when the job started, so without this the
		// remaining backlog would be abandoned. Safe to resume, and safe to reset
		// the attempt count: the rows this run deleted are gone for good, so a
		// run that makes progress cannot loop.
		$this->jobList->expects(self::once())
			->method('add')
			->with(PurgeOrphanChangeDetails::class, ['attempt' => 0]);

		$this->runJob(['attempt' => 3]);
	}

	public function testAFailureWithoutProgressRetriesOnABudget(): void {
		$this->changeDetailMapper->method('findOrphanIds')
			->willThrowException(new \RuntimeException('db went away'));
		// The migration enqueues this job exactly once and never re-runs, so one
		// transient failure must not abandon the backlog - it burns an attempt.
		$this->jobList->expects(self::once())
			->method('add')
			->with(PurgeOrphanChangeDetails::class, ['attempt' => 1]);

		$this->runJob();
	}

	public function testARepeatedlyFailingScanStopsAtTheAttemptCap(): void {
		$this->changeDetailMapper->method('findOrphanIds')
			->willThrowException(new \RuntimeException('db went away'));
		// A scan that never works is not worth repeating on every cron pass.
		$this->jobList->expects(self::never())->method('add');

		$this->runJob(['attempt' => PurgeOrphanChangeDetails::MAX_ATTEMPTS - 1]);
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Db\ChangeDetailMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-off cleanup of `kanso_change_details` rows that were orphaned before
 * {@see \OCA\Kanso\Db\ChangeMapper::deleteByIds()} learned to delete a change
 * row's details with it.
 *
 * Until that fix, {@see PruneChanges} deleted the parent `kanso_changes` rows on
 * their own at the 30-day mark. A detail row has no board id and no card id, so
 * its parent was the only handle anything had on it: every row the pruner left
 * behind is unreachable by any purge path - including the board cascade, which
 * finds details only through their parent - and accumulates on every instance
 * forever. This job drains that backlog once.
 *
 * Why a job and not a migration: the backlog is proportional to everything an
 * instance ever pruned, so on a busy server it can be a very large delete, and a
 * migration runs inside `occ upgrade` while the instance sits in maintenance
 * mode. A slow delete there stalls the upgrade (or times out mid-way), which is
 * exactly the failure the cleanup must not cause. Enqueued by
 * {@see \OCA\Kanso\Migration\Version006200Date20260918000000} instead, so the
 * upgrade only writes one job row and cron does the work afterwards, in the
 * background, at its own pace. It needs no admin action either - which an `occ`
 * command would, and most instances would simply never run it.
 *
 * Bounded twice over: {@see self::BATCH_SIZE} rows per statement and
 * {@see self::MAX_BATCHES} batches per EXECUTION, so no single execution can
 * run long however deep the backlog - cron always gets its thread back on a
 * bounded schedule rather than draining an unknown number of rows in one go. If
 * an execution ends with the cap still saturated it queues a continuation, which
 * cron picks up on its next pass through the job list; when the orphan scan
 * comes back short, no continuation is queued, the queue entry is gone (a
 * QueuedJob is removed as it starts) and the scan never runs again. That last
 * part matters: the scan is an anti-join over the whole side table, which is
 * precisely why it must not live in a recurring job.
 */
class PurgeOrphanChangeDetails extends QueuedJob {
	/** Rows deleted per batch - keeps individual DELETEs short. */
	public const BATCH_SIZE = 1000;

	/** Upper bound on batches per execution so a huge backlog cannot stall cron. */
	public const MAX_BATCHES = 20;

	/**
	 * How often the drain may be retried after a run that removed nothing. The
	 * migration enqueues this job exactly ONCE and an applied migration never
	 * re-runs, so a single transient failure (a lock timeout, a DB restart
	 * mid-cron) would otherwise abandon the backlog on that instance for good.
	 * A run that made progress resets the count - progress is monotonic, so
	 * resuming after one always terminates.
	 */
	public const MAX_ATTEMPTS = 5;

	public function __construct(
		ITimeFactory $time,
		private ChangeDetailMapper $changeDetailMapper,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$attempt = is_array($argument) && isset($argument['attempt']) ? (int)$argument['attempt'] : 0;
		$deleted = 0;
		try {
			for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
				$ids = $this->changeDetailMapper->findOrphanIds(self::BATCH_SIZE);
				if ($ids === []) {
					$this->logBacklogCleared($deleted);
					return;
				}
				$deleted += $this->changeDetailMapper->deleteByIds($ids);
				if (count($ids) < self::BATCH_SIZE) {
					$this->logBacklogCleared($deleted);
					return;
				}
			}
		} catch (\Throwable $e) {
			// A QueuedJob is taken off the list as it starts, so a throw would
			// otherwise abandon the backlog with nothing left to re-trigger the
			// drain. A run that deleted rows resumes on a fresh attempt budget
			// (progress is monotonic, so it terminates); a run that deleted
			// nothing burns one attempt, so a permanently failing scan stops
			// after MAX_ATTEMPTS instead of repeating on every cron pass.
			$this->logger->error(
				'Kanso: orphaned change-detail cleanup failed after {deleted} rows (attempt {attempt})',
				['app' => 'kanso', 'deleted' => $deleted, 'attempt' => $attempt + 1, 'exception' => $e],
			);
			if ($deleted > 0) {
				$this->requeue(0);
			} elseif ($attempt + 1 < self::MAX_ATTEMPTS) {
				$this->requeue($attempt + 1);
			}
			return;
		}

		// The execution ended with the cap saturated, so there may be more. A
		// QueuedJob is removed from the list as it starts, so this queues exactly
		// one continuation rather than duplicating the job.
		$this->requeue(0);
		$this->logger->info(
			'Kanso: removed {deleted} orphaned change-detail rows, more remain - continuing on the next run',
			['app' => 'kanso', 'deleted' => $deleted],
		);
	}

	private function requeue(int $attempt): void {
		$this->jobList->add(self::class, ['attempt' => $attempt]);
	}

	private function logBacklogCleared(int $deleted): void {
		if ($deleted > 0) {
			$this->logger->info(
				'Kanso: removed {deleted} orphaned change-detail rows, backlog cleared',
				['app' => 'kanso', 'deleted' => $deleted],
			);
		}
	}
}

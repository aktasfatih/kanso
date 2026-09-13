<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCA\Kanso\Cron\PurgeOrphanChangeDetails;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Queue the one-off cleanup of orphaned `kanso_change_details` rows.
 *
 * Until {@see \OCA\Kanso\Db\ChangeMapper::deleteByIds()} started deleting a
 * change row's details with it, the 30-day change-log prune deleted the parent
 * rows alone. A detail row has no board id and no card id, so the leftovers are
 * unreachable by every purge path there is and every instance has been
 * accumulating them for as long as it has been pruning.
 *
 * Schema-less on purpose: this step only enqueues
 * {@see PurgeOrphanChangeDetails}, which drains the backlog in bounded batches
 * on ordinary cron runs and then removes itself. Doing the delete HERE would put
 * an unbounded delete inside `occ upgrade`, with the instance in maintenance
 * mode - the one thing a cleanup for a bookkeeping table must never risk.
 *
 * The job is deliberately absent from info.xml <background-jobs>: that list is
 * for recurring jobs and is re-synced on install, whereas this one must run once
 * and disappear. Enqueuing it here covers both a fresh install (where it finds
 * nothing and stops) and an upgrade; the add is guarded so re-running is a
 * no-op.
 */
class Version006200Date20260918000000 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->jobList->has(PurgeOrphanChangeDetails::class, null)) {
			$this->jobList->add(PurgeOrphanChangeDetails::class);
			$output->info('Queued one-off cleanup: PurgeOrphanChangeDetails');
		}
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCA\Kanso\Cron\SpawnRecurringCards;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Ensure the recurring-card spawner cron is registered on this instance (#65).
 *
 * {@see \OCA\Kanso\Cron\SpawnRecurringCards} is declared in info.xml
 * <background-jobs>, but Nextcloud only syncs that list on a FRESH install, not
 * when an existing install is upgraded. Instances that installed Kanso before
 * the recurring-cards feature shipped therefore never had the job registered,
 * so their recurring rules silently never fired.
 *
 * This is schema-less: it only (idempotently) adds the background job in
 * postSchemaChange, mirroring the SendPersonalReminders registration in
 * {@see Version005100Date20260908000000}. Registering it here covers both fresh
 * install and upgrade; the jobList add is guarded so re-running is a no-op.
 */
class Version005300Date20260909000000 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * Ensure the recurring-card spawner is registered on this instance. Runs on
	 * install and on upgrade (info.xml only auto-registers <background-jobs> on a
	 * fresh install), guarded so re-running is a no-op.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->jobList->has(SpawnRecurringCards::class, null)) {
			$this->jobList->add(SpawnRecurringCards::class);
			$output->info('Registered background job: SpawnRecurringCards');
		}
	}
}

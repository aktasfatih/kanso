<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCA\Kanso\Cron\PollMailIntake;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version007300Date20261031000000 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Nextcloud persists the old time-insensitive flag on the job record.
		$this->jobList->remove(PollMailIntake::class);
		$this->jobList->add(PollMailIntake::class);
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Service\RecurrenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * 15-minute recurring-card spawner. Iterates every enabled rule whose cached
 * next fire time has passed ({@see \OCA\Kanso\Db\RecurRuleMapper::findDueEnabled})
 * and spawns each one. Per-rule error handling lives in
 * {@see RecurrenceService::runDueRules} - one broken rule (deleted template,
 * lost board access) is logged and skipped so it cannot stall the job.
 */
class SpawnRecurringCards extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private RecurrenceService $recurrenceService,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 15);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->recurrenceService->runDueRules();
	}
}

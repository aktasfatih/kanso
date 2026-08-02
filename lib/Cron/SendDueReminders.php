<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Service\DueReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * 15-minute due-date reminder sweep. Notifies each due card's assignees and
 * watchers - AT due time, plus an optional "1 day before" (the card's fixed
 * opt-in). Each reminder is stamped on the card so it fires once and is re-armed
 * only when the due date changes; the sweep is bounded per run and per-card
 * error-isolated. All of that lives in {@see DueReminderService::runDueReminders}.
 */
class SendDueReminders extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private DueReminderService $dueReminderService,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 15);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->dueReminderService->runDueReminders();
	}
}

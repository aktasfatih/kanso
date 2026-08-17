<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Service\ReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * 15-minute personal "remind me" sweep (#3816). Fires each owed one-shot
 * reminder to the user who set it (bell + push), deep-linking to the card. Each
 * reminder is stamped `fired_at` so it fires once and any overdue backlog is
 * caught up on the next run; the sweep is bounded per run and per-reminder
 * error-isolated. All of that lives in {@see ReminderService::fireDue()}.
 */
class SendPersonalReminders extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ReminderService $reminderService,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 15);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$this->reminderService->fireDue();
	}
}

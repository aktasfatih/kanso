<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Service\MailIntakeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Five-minute email-intake poller (#117): every board with an enabled mailbox
 * gets its new mail turned into cards.
 *
 * Five minutes is the compromise between "mail should feel prompt" and the cost
 * of a TLS handshake plus LOGIN per mailbox per tick. Per-mailbox error
 * handling, the message budget and the UID watermark all live in
 * {@see MailIntakeService::pollAll} - one unreachable server is recorded on its
 * own board and stepped over, so it cannot stall the others.
 *
 * TIME_INSENSITIVE: intake is not something a user is waiting on in the moment,
 * so it belongs in the window where NC is happy to run heavier work.
 */
class PollMailIntake extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private MailIntakeService $mailIntakeService,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 5);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$this->mailIntakeService->pollAll();
	}
}

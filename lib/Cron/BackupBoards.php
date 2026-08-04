<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Service\BackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily instance-wide board backup sweep. When the admin has enabled backups,
 * every board is exported to its versioned JSON and written to the configured
 * Nextcloud path, retaining the last N per board. All of the enabled-gate,
 * per-board isolation, retention prune and last-run recording lives in
 * {@see BackupService::run}; this job is a thin, time-insensitive delegator.
 */
class BackupBoards extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private BackupService $backupService,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$this->backupService->run();
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Service\ArchiveService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Hourly auto-archive sweep. Iterates every enabled rule in
 * `kanso_archive_rules` and archives the done cards that have crossed each
 * rule's age threshold. Each rule is capped at
 * {@see ArchiveService::MAX_PER_SWEEP} cards per pass (mirroring
 * PruneChanges' batch discipline), so a board with years of done cards
 * cannot stall the job — the remainder is picked up next hour.
 */
class ArchiveDoneCards extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ArchiveService $archiveService,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->archiveService->runEnabledRules();
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Db\ChangeMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily pruning of the kanso_changes log, which grows by one row per
 * mutation. Deletes rows older than the retention window in batches, but
 * never a board's newest row: getLatestChangeId() (the ETag source) must
 * not regress to 0 for idle boards.
 */
class PruneChanges extends TimedJob {
	/** 30 days - generous overlap for any future delta-sync polling. */
	public const RETENTION_SECONDS = 30 * 24 * 3600;

	/** Rows deleted per batch - keeps individual DELETEs short. */
	public const BATCH_SIZE = 1000;

	/** Upper bound on batches per run so a huge backlog cannot stall cron. */
	public const MAX_BATCHES = 20;

	public function __construct(
		ITimeFactory $time,
		private ChangeMapper $changeMapper,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$cutoff = $this->time->getTime() - self::RETENTION_SECONDS;
		for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
			$ids = $this->changeMapper->findPrunableIds($cutoff, self::BATCH_SIZE);
			if ($ids === []) {
				return;
			}
			$this->changeMapper->deleteByIds($ids);
			if (count($ids) < self::BATCH_SIZE) {
				return;
			}
		}
	}
}

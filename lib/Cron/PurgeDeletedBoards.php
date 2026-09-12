<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Service\BoardPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily reaping of soft-deleted boards. Deleting a board writes a `deleted_at`
 * tombstone and takes it out of every read path; this job is what eventually
 * removes the rows and the attachment bytes, once the retention window has
 * passed.
 *
 * The window matches {@see PruneChanges::RETENTION_SECONDS} deliberately: one
 * retention story for the whole app, not two numbers to reconcile. There is no
 * restore during it - boards have a separate `archived` flag for the reversible
 * case - it is a safety margin against an accidental delete being irreversible
 * the same second it happens.
 *
 * Batched and capped like the change pruner: a fixed number of boards per run,
 * so an instance that just lost a hundred boards cannot stall cron. Whatever is
 * left waits for tomorrow.
 *
 * One board's failure never blocks the queue: the purge is per-board and
 * atomic, so a board that throws (or whose attachment bytes could not be
 * removed) keeps its tombstone, gets logged, and is retried on the next run
 * while the rest of the batch still drains.
 */
class PurgeDeletedBoards extends TimedJob {
	/** 30 days - the same retention window the change log uses. */
	public const RETENTION_SECONDS = 30 * 24 * 3600;

	/** Upper bound on boards reaped per run so a mass delete cannot stall cron. */
	public const MAX_BOARDS_PER_RUN = 20;

	public function __construct(
		ITimeFactory $time,
		private BoardPurgeService $boardPurgeService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$cutoff = $this->time->getTime() - self::RETENTION_SECONDS;
		$boardIds = $this->boardPurgeService->findPurgeable($cutoff, self::MAX_BOARDS_PER_RUN);

		foreach ($boardIds as $boardId) {
			try {
				$this->boardPurgeService->purge($boardId);
			} catch (\Throwable $e) {
				// Keep draining the batch: the failed board still carries its
				// tombstone, so the next run picks it up again.
				$this->logger->error(
					'Kanso: purging deleted board {boardId} failed',
					['app' => 'kanso', 'boardId' => $boardId, 'exception' => $e],
				);
			}
		}
	}
}

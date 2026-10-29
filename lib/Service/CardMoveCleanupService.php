<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Cron\CleanupFailedMoveAttachments;
use OCP\BackgroundJob\IJobList;

class CardMoveCleanupService {
	private const BATCH_SIZE = 50;

	public function __construct(
		private IJobList $jobs,
		private CardAttachmentService $attachments,
	) {
	}

	/** @param list<array{cardId: int, key: string}> $objects */
	public function schedule(array $objects): void {
		foreach (array_chunk($objects, self::BATCH_SIZE) as $chunk) {
			$this->jobs->add(CleanupFailedMoveAttachments::class, ['objects' => $chunk]);
		}
	}

	/** @param list<array{cardId: int, key: string}> $objects */
	public function cleanup(array $objects): void {
		if ($this->attachments->deleteUnreferencedMoveObjects($objects) !== 0) {
			throw new \RuntimeException('Could not clean up attachments for the failed card move');
		}
	}
}

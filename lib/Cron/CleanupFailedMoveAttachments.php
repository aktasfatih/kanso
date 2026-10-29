<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Service\CardAttachmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class CleanupFailedMoveAttachments extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private CardAttachmentService $attachments,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$objects = is_array($argument) ? ($argument['objects'] ?? null) : null;
		if (!is_array($objects)) {
			return;
		}
		$valid = [];
		foreach ($objects as $object) {
			if (!is_array($object) || !is_int($object['cardId'] ?? null) || $object['cardId'] <= 0
				|| !is_string($object['key'] ?? null) || !preg_match('/^[a-z0-9]{32}$/D', $object['key'])) {
				$this->logger->warning('Kanso: invalid failed-move attachment cleanup record');
				return;
			}
			$valid[] = ['cardId' => $object['cardId'], 'key' => $object['key']];
		}
		try {
			if ($this->attachments->deleteUnreferencedMoveObjects($valid) === 0) {
				return;
			}
			throw new \RuntimeException('Attachment cleanup failed');
		} catch (\Throwable $e) {
			$this->logger->warning('Kanso: failed-move attachment cleanup will be retried', ['exception' => $e]);
			$this->jobs->scheduleAfter(self::class, $this->time->getTime() + 3600, ['objects' => $valid]);
		}
	}
}

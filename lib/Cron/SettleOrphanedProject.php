<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Cron;

use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Service\ProjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Retries the settlement of a project whose owner's account was deleted, when
 * the attempt made during the deletion failed.
 */
class SettleOrphanedProject extends QueuedJob {
	public const MAX_ATTEMPTS = 24;
	private const RETRY_DELAY = 3600;

	public function __construct(
		ITimeFactory $time,
		private ProjectMapper $projectMapper,
		private ProjectService $projectService,
		private IUserManager $userManager,
		private IJobList $jobs,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		$projectId = is_array($argument) ? ($argument['projectId'] ?? null) : null;
		$formerOwner = is_array($argument) ? ($argument['formerOwner'] ?? null) : null;
		if (!is_int($projectId) || !is_string($formerOwner)) {
			return;
		}
		$attempt = (int)($argument['attempt'] ?? 0);

		try {
			$project = $this->projectMapper->find($projectId);
			if ($project->getOwner() === $formerOwner && !$this->userManager->userExists($formerOwner)) {
				// Core drops a deleted user from their groups first, so a group that
				// held only the owner is truly empty once retries have outlasted an outage.
				$this->projectService->handOver($project, $formerOwner, $attempt + 1 >= self::MAX_ATTEMPTS);
			}
		} catch (DoesNotExistException) {
			return;
		} catch (\Throwable $e) {
			$this->retry($projectId, $formerOwner, $attempt + 1, $e);
		}
	}

	private function retry(int $projectId, string $formerOwner, int $attempt, \Throwable $e): void {
		if ($attempt >= self::MAX_ATTEMPTS) {
			$this->logger->error('Giving up on settling project ' . $projectId . ' of a deleted user', ['exception' => $e]);
			return;
		}
		$this->logger->warning('Settling project ' . $projectId . ' of a deleted user will be retried', ['exception' => $e]);
		$this->jobs->scheduleAfter(self::class, $this->time->getTime() + self::RETRY_DELAY, [
			'projectId' => $projectId,
			'formerOwner' => $formerOwner,
			'attempt' => $attempt,
		]);
	}
}

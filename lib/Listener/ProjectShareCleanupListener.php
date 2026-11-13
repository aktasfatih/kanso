<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Listener;

use OCA\Kanso\Cron\SettleOrphanedProject;
use OCA\Kanso\Db\Acl;
use OCA\Kanso\Db\BoardGroupMapper;
use OCA\Kanso\Db\BoardGroupMemberMapper;
use OCA\Kanso\Db\BoardPinMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ProjectPinMapper;
use OCA\Kanso\Db\ProjectShareMapper;
use OCA\Kanso\Service\ProjectService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Shares and owners match on the id string, so a row outliving its user or
 * group would hand the project to whoever reuses that id.
 *
 * @implements IEventListener<UserDeletedEvent|GroupDeletedEvent>
 */
class ProjectShareCleanupListener implements IEventListener {
	public function __construct(
		private ProjectShareMapper $shareMapper,
		private ProjectPinMapper $pinMapper,
		private BoardPinMapper $boardPinMapper,
		private BoardGroupMemberMapper $groupMemberMapper,
		private BoardGroupMapper $groupMapper,
		private ProjectMapper $projectMapper,
		private ProjectService $projectService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$uid = $event->getUser()->getUID();
			try {
				$this->shareMapper->deleteByParticipant(Acl::TYPE_USER, $uid);
			} catch (\Throwable $e) {
				$this->logger->error('Could not remove the project shares of a deleted user', ['exception' => $e]);
			}
			try {
				$this->pinMapper->deleteByUser($uid);
			} catch (\Throwable $e) {
				$this->logger->error('Could not remove the project pins of a deleted user', ['exception' => $e]);
			}
			try {
				$this->boardPinMapper->deleteByUser($uid);
			} catch (\Throwable $e) {
				$this->logger->error('Could not remove the board pins of a deleted user', ['exception' => $e]);
			}
			try {
				$this->groupMemberMapper->deleteByUser($uid);
			} catch (\Throwable $e) {
				$this->logger->error('Could not remove the folder memberships of a deleted user', ['exception' => $e]);
			}
			try {
				$this->groupMapper->deleteByUser($uid);
			} catch (\Throwable $e) {
				$this->logger->error('Could not remove the folders of a deleted user', ['exception' => $e]);
			}
			try {
				$this->projectMapper->clearLeadByUser($uid);
			} catch (\Throwable $e) {
				$this->logger->error('Could not clear the project leads of a deleted user', ['exception' => $e]);
			}
			$this->settleOwnedProjects($uid);
		} elseif ($event instanceof GroupDeletedEvent) {
			$this->shareMapper->deleteByParticipant(Acl::TYPE_GROUP, $event->getGroup()->getGID());
		}
	}

	private function settleOwnedProjects(string $uid): void {
		foreach ($this->projectMapper->findOwnedBy($uid) as $project) {
			try {
				$this->projectService->handOver($project, $uid);
			} catch (\Throwable $e) {
				$this->logger->error('Could not settle project ' . $project->getId() . ' of a deleted user', ['exception' => $e]);
				$this->queueRetry($project->getId(), $uid);
			}
		}
	}

	private function queueRetry(int $projectId, string $uid): void {
		try {
			$this->jobList->add(SettleOrphanedProject::class, ['projectId' => $projectId, 'formerOwner' => $uid, 'attempt' => 0]);
		} catch (\Throwable $e) {
			$this->logger->error('Could not queue a retry for project ' . $projectId, ['exception' => $e]);
		}
	}
}

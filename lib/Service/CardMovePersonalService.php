<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardRunningTimerMapper;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\ReminderMapper;
use OCA\Kanso\Db\Subscription;
use OCA\Kanso\Db\SubscriptionMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class CardMovePersonalService {
	public function __construct(
		private ReminderMapper $reminders,
		private SubscriptionMapper $subscriptions,
		private ProjectCardMapper $projectCards,
		private ProjectMapper $projects,
		private CardRunningTimerMapper $timers,
		private PermissionService $permissions,
		private CardVisibilityGuard $visibility,
	) {
	}

	/**
	 * Transfer personal and active state inside the caller's move transaction.
	 *
	 * @param array<int, int> $commentIds old comment ID to new comment ID
	 */
	public function transfer(int $sourceId, Card $target, Board $targetBoard, array $commentIds): void {
		$reminders = $this->reminders->findByCardForMove($sourceId);
		$subscriptions = $this->subscriptions->findByCardForMove($sourceId);
		$memberships = $this->projectCards->findByCardForMove($sourceId);
		$verified = [];
		$assertAccess = function (string $uid) use (&$verified, $target, $targetBoard): void {
			if (isset($verified[$uid])) {
				return;
			}
			if (($this->permissions->getPermissions($targetBoard, $uid) & PermissionService::PERMISSION_READ) === 0
				|| !$this->visibility->isVisible($targetBoard, $target, $uid)) {
				throw new InvalidInputException('The card cannot be moved because some card data cannot be preserved on the destination board');
			}
			$verified[$uid] = true;
		};

		foreach ($reminders as $reminder) {
			$assertAccess($reminder->getUserId());
			$commentId = $reminder->getCommentId();
			if ($commentId !== null && !isset($commentIds[$commentId])) {
				throw new InvalidInputException('The card cannot be moved because some card data cannot be preserved on the destination board');
			}
		}
		foreach ($subscriptions as $subscription) {
			// Active card-wide watchers are already copied by CardService.
			if ($subscription->getCommentThreadId() === SubscriptionMapper::THREAD_CARD
				&& $subscription->getState() === Subscription::STATE_SUBSCRIBED) {
				continue;
			}
			$assertAccess($subscription->getSubscriber());
			$threadId = $subscription->getCommentThreadId();
			if ($threadId !== SubscriptionMapper::THREAD_CARD && !isset($commentIds[$threadId])) {
				throw new InvalidInputException('The card cannot be moved because some card data cannot be preserved on the destination board');
			}
		}
		foreach ($memberships as $membership) {
			try {
				$project = $this->projects->find($membership->getProjectId());
			} catch (DoesNotExistException $e) {
				throw new InvalidInputException('The card cannot be moved because some card data cannot be preserved on the destination board');
			}
			$assertAccess($project->getOwner());
		}
		try {
			$timer = $this->timers->findByCard($sourceId);
		} catch (DoesNotExistException $e) {
			$timer = null;
		}
		if ($timer !== null) {
			$assertAccess($timer->getStartedBy());
		}

		foreach ($reminders as $reminder) {
			$reminder->setCardId($target->getId());
			$commentId = $reminder->getCommentId();
			if ($commentId !== null) {
				$reminder->setCommentId($commentIds[$commentId]);
			}
			$this->reminders->update($reminder);
		}
		foreach ($subscriptions as $subscription) {
			$threadId = $subscription->getCommentThreadId();
			if ($threadId === SubscriptionMapper::THREAD_CARD && $subscription->getState() === Subscription::STATE_SUBSCRIBED) {
				continue;
			}
			$subscription->setCardId($target->getId());
			if ($threadId !== SubscriptionMapper::THREAD_CARD) {
				$subscription->setCommentThreadId($commentIds[$threadId]);
			}
			$this->subscriptions->update($subscription);
		}
		foreach ($memberships as $membership) {
			$membership->setCardId($target->getId());
			$this->projectCards->update($membership);
		}
		if ($timer !== null) {
			$timer->setCardId($target->getId());
			$timer->setBoardId($targetBoard->getId());
			$this->timers->update($timer);
		}
	}
}

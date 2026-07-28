<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCP\Notification\IManager;

/**
 * Targeted (single-user) Nextcloud notifications - the delivery leg that the
 * board-scoped {@see ChangeNotifier} (realtime fan-out to everyone) cannot
 * provide. Thin wrapper over the NC notification manager, keyed by a small set
 * of string subjects parsed back by {@see \OCA\Kanso\Notification\Notifier}.
 *
 * v1 subject: `card_assigned`. A user is never notified of their own action.
 * The reverse action dismisses the matching notification so a stale "assigned
 * to you" doesn't linger after an unassign.
 */
class NotificationService {
	public const SUBJECT_CARD_ASSIGNED = 'card_assigned';
	public const SUBJECT_CARD_COMMENT = 'card_comment';
	public const SUBJECT_CARD_REVIEW_REQUESTED = 'card_review_requested';
	public const SUBJECT_BOARD_ACTIVITY = 'board_activity';
	public const OBJECT_CARD = 'card';

	public function __construct(
		private IManager $manager,
	) {
	}

	/**
	 * Notifies $targetUid that $actorUid assigned them to the card. No-op when
	 * the actor assigns themselves.
	 */
	public function notifyCardAssigned(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_ASSIGNED, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that $actorUid commented on a card they watch. No-op
	 * when the commenter is the target (you don't get notified of your own
	 * comment even though commenting auto-subscribes you).
	 */
	public function notifyCardComment(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_COMMENT, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that $actorUid requested a review from them. No-op
	 * when the actor requests a review from themselves.
	 */
	public function notifyReviewRequested(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_REVIEW_REQUESTED, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Notifies $targetUid that $actorUid created a card on a board they watch.
	 * No-op when the creator is the target (you don't get notified of your own
	 * card even though board activity fans out to the other watchers). The
	 * notification links to the new card.
	 */
	public function notifyBoardActivity(int $cardId, string $targetUid, string $actorUid): void {
		if ($targetUid === $actorUid) {
			return;
		}

		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setDateTime((new \DateTime())->setTimestamp(time()))
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_BOARD_ACTIVITY, ['actor' => $actorUid, 'cardId' => $cardId]);

		$this->manager->notify($notification);
	}

	/**
	 * Dismisses a previously-sent "assigned to you" notification for the card
	 * (e.g. after the user is unassigned). Idempotent - dismissing an absent
	 * notification is a no-op at the manager level.
	 */
	public function dismissCardAssigned(int $cardId, string $targetUid): void {
		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_ASSIGNED);

		$this->manager->markProcessed($notification);
	}

	/**
	 * Dismisses a previously-sent "review requested" notification for the card
	 * (e.g. after the request is withdrawn or the reviewer has acted).
	 * Idempotent - dismissing an absent notification is a no-op.
	 */
	public function dismissReviewRequested(int $cardId, string $targetUid): void {
		$notification = $this->manager->createNotification();
		$notification->setApp('kanso')
			->setUser($targetUid)
			->setObject(self::OBJECT_CARD, (string)$cardId)
			->setSubject(self::SUBJECT_CARD_REVIEW_REQUESTED);

		$this->manager->markProcessed($notification);
	}
}

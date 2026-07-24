<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Subscription;
use OCA\Kanso\Db\SubscriptionMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Card / thread watchers. You can watch any card you can READ; a manual
 * unsubscribe writes an opt-out tombstone so later auto-subscribe never re-adds
 * you. Commenting on or being assigned to a card auto-subscribes you (unless
 * you opted out). Activity on a watched card/thread fans out an out-of-band NC
 * notification to the other watchers via {@see NotificationService} (in-app
 * realtime already rides the board's existing push).
 *
 * A subscribe/unsubscribe is deliberately NOT a board change row — it is
 * personal state, so it doesn't churn the board ETag for everyone; the client
 * reconciles its own card cache.
 */
class SubscriptionService {
	public function __construct(
		private SubscriptionMapper $subscriptionMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private NotificationService $notificationService,
	) {
	}

	/**
	 * The card-level watch state for a user: are they subscribed, who else is,
	 * and how many. Requires READ.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int}
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function getCardSubscription(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		return $this->buildCardSubscription($cardId, $actorUid);
	}

	/**
	 * Subscribes the actor to the whole card (clears any opt-out). Requires READ.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int}
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function subscribe(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		$this->setState($actorUid, $cardId, SubscriptionMapper::THREAD_CARD, Subscription::STATE_SUBSCRIBED);

		return $this->buildCardSubscription($cardId, $actorUid);
	}

	/**
	 * Unsubscribes the actor from the card, writing an opt-out tombstone so
	 * auto-subscribe won't silently re-add them. Requires READ.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int}
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function unsubscribe(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		$this->setState($actorUid, $cardId, SubscriptionMapper::THREAD_CARD, Subscription::STATE_OPTED_OUT);

		return $this->buildCardSubscription($cardId, $actorUid);
	}

	/**
	 * Auto-subscribes a user to a card/thread scope IF they have not explicitly
	 * opted out. No permission check (the caller — assign/comment — has already
	 * gated). Idempotent.
	 */
	public function autoSubscribe(int $cardId, int $threadId, string $uid): void {
		$existing = $this->subscriptionMapper->findOne($uid, $cardId, $threadId);
		if ($existing !== null) {
			// Respect an opt-out tombstone; an existing subscription stays as-is.
			return;
		}
		$this->insert($uid, $cardId, $threadId, Subscription::STATE_SUBSCRIBED);
	}

	/**
	 * A new comment landed: auto-subscribe the commenter and notify the other
	 * watchers of the card (and of the specific thread when it's a reply).
	 * $parentCommentId is the replied-to top-level comment id, or null for a
	 * new top-level comment.
	 */
	public function handleNewComment(int $cardId, ?int $parentCommentId, string $actorUid): void {
		$threadId = $parentCommentId ?? SubscriptionMapper::THREAD_CARD;

		// The commenter watches the card; a replier also watches that thread.
		$this->autoSubscribe($cardId, SubscriptionMapper::THREAD_CARD, $actorUid);
		if ($parentCommentId !== null) {
			$this->autoSubscribe($cardId, $parentCommentId, $actorUid);
		}

		foreach ($this->subscriptionMapper->findNotifyUids($cardId, $threadId) as $uid) {
			if ($uid === $actorUid) {
				continue;
			}
			$this->notificationService->notifyCardComment($cardId, $uid, $actorUid);
		}
	}

	/**
	 * The card-level watch block for a user WITHOUT a permission check — the
	 * caller (e.g. the card-detail payload) has already established READ.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int}
	 * @throws \OCP\DB\Exception
	 */
	public function buildCardSubscription(int $cardId, string $actorUid): array {
		$mine = $this->subscriptionMapper->findOne($actorUid, $cardId, SubscriptionMapper::THREAD_CARD);
		$subscribers = $this->subscriptionMapper->findCardSubscriberUids($cardId);
		return [
			'subscribed' => $mine !== null && $mine->getState() === Subscription::STATE_SUBSCRIBED,
			'subscribers' => $subscribers,
			'count' => count($subscribers),
		];
	}

	/**
	 * Upserts a subscription row to the given state.
	 */
	private function setState(string $uid, int $cardId, int $threadId, int $state): void {
		$existing = $this->subscriptionMapper->findOne($uid, $cardId, $threadId);
		if ($existing !== null) {
			if ($existing->getState() !== $state) {
				$existing->setState($state);
				$this->subscriptionMapper->update($existing);
			}
			return;
		}
		$this->insert($uid, $cardId, $threadId, $state);
	}

	private function insert(string $uid, int $cardId, int $threadId, int $state): void {
		$sub = new Subscription();
		$sub->setSubscriber($uid);
		$sub->setCardId($cardId);
		$sub->setCommentThreadId($threadId);
		$sub->setState($state);
		$sub->setCreatedAt(time());
		try {
			$this->subscriptionMapper->insert($sub);
		} catch (\OCP\DB\Exception $e) {
			// Concurrent insert lost the unique race — the row now exists, which
			// is the idempotent success case for a subscribe/auto-subscribe.
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * @throws DoesNotExistException if the card does not exist or is deleted
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}

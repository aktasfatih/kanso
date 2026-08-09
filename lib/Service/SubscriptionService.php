<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\BoardSubscription;
use OCA\Kanso\Db\BoardSubscriptionMapper;
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
 * A subscribe/unsubscribe is deliberately NOT a board change row - it is
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
		private BoardSubscriptionMapper $boardSubscriptionMapper,
		private CardVisibilityGuard $visibilityGuard,
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
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

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
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

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
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		$this->setState($actorUid, $cardId, SubscriptionMapper::THREAD_CARD, Subscription::STATE_OPTED_OUT);

		return $this->buildCardSubscription($cardId, $actorUid);
	}

	/**
	 * Subscribes ANOTHER board participant to the card as a watcher. The actor
	 * needs EDIT on the board; the target must at least READ it - watching an
	 * outsider would create a card reference their board payload can never
	 * resolve (mirrors {@see AssigneeService::assign}). An explicit add clears
	 * any opt-out tombstone, exactly like a self-subscribe. Idempotent.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int} the actor's own watch block, with the refreshed subscriber list
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the target cannot read the board (or does not exist)
	 */
	public function subscribeOther(int $cardId, string $targetUid, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		// Directly or via a group ACL, the target must at least see the board.
		// Unknown users hold no permissions, so they fail this too.
		if (($this->permissionService->getPermissions($board, $targetUid) & PermissionService::PERMISSION_READ) === 0) {
			throw new InvalidInputException('User has no access to this board');
		}

		$this->setState($targetUid, $cardId, SubscriptionMapper::THREAD_CARD, Subscription::STATE_SUBSCRIBED);

		return $this->buildCardSubscription($cardId, $actorUid);
	}

	/**
	 * Removes ANOTHER watcher from the card. The actor needs EDIT on the board.
	 * Tombstones an EXISTING subscription (same as a self-unsubscribe) so a later
	 * comment/assignment auto-subscribe won't silently undo the removal; the
	 * target can always re-add themselves. Removing a user who is not currently
	 * watching is a no-op (never plants a stale opt-out). Idempotent.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int} the actor's own watch block, with the refreshed subscriber list
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function unsubscribeOther(int $cardId, string $targetUid, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		// Only tombstone an EXISTING subscription row. Planting an opt-out for a
		// user who is not currently watching would silently suppress a later
		// auto-subscribe once they join/comment - so removing a non-watcher is a
		// no-op rather than a crafted-uid tombstone.
		$existing = $this->subscriptionMapper->findOne($targetUid, $cardId, SubscriptionMapper::THREAD_CARD);
		if ($existing !== null && $existing->getState() !== Subscription::STATE_OPTED_OUT) {
			$existing->setState(Subscription::STATE_OPTED_OUT);
			$this->subscriptionMapper->update($existing);
		}

		return $this->buildCardSubscription($cardId, $actorUid);
	}

	/**
	 * Auto-subscribes a user to a card/thread scope IF they have not explicitly
	 * opted out. No permission check (the caller - assign/comment - has already
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
	 *
	 * The watcher fan-out is RESTRICTED to watchers who can still SEE the card
	 * (#3760): a watcher subscribed while the card was visible to them must
	 * stop receiving artifacts once its visibility narrows past them - the
	 * bell entry would be an existence oracle. One batched role resolution
	 * ({@see CardVisibilityGuard::filterVisible()}), no per-watcher queries.
	 */
	public function handleNewComment(int $cardId, ?int $parentCommentId, string $actorUid): void {
		$threadId = $parentCommentId ?? SubscriptionMapper::THREAD_CARD;

		// The commenter watches the card; a replier also watches that thread.
		$this->autoSubscribe($cardId, SubscriptionMapper::THREAD_CARD, $actorUid);
		if ($parentCommentId !== null) {
			$this->autoSubscribe($cardId, $parentCommentId, $actorUid);
		}

		$watchers = array_filter(
			$this->subscriptionMapper->findNotifyUids($cardId, $threadId),
			static fn (string $uid): bool => $uid !== $actorUid,
		);
		if ($watchers === []) {
			return;
		}
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		foreach ($this->visibilityGuard->filterVisible($board, $card, $watchers) as $uid) {
			$this->notificationService->notifyCardComment($cardId, $uid, $actorUid);
		}
	}

	/**
	 * The card-level watch block for a user WITHOUT a permission check - the
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

	// ---- Board-level subscriptions -------------------------------------------
	//
	// Watching a whole board is a presence model (a row means subscribed) in a
	// separate table, NOT the card-keyed subscription store: there is no
	// auto-subscribe to a board, so no opt-out tombstone is needed. Watchers get
	// a fixed signal - a new card was created on the board - nothing else (the
	// noise trap from #3426).

	/**
	 * The board-level watch state for a user: are they subscribed, who else is,
	 * and how many. Requires READ.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function getBoardSubscription(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		return $this->buildBoardSubscription($boardId, $actorUid);
	}

	/**
	 * Subscribes the actor to the whole board. Idempotent. Requires READ.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function subscribeBoard(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		if ($this->boardSubscriptionMapper->findOne($actorUid, $boardId) === null) {
			$sub = new BoardSubscription();
			$sub->setSubscriber($actorUid);
			$sub->setBoardId($boardId);
			$sub->setCreatedAt(time());
			try {
				$this->boardSubscriptionMapper->insert($sub);
			} catch (\OCP\DB\Exception $e) {
				// Concurrent subscribe lost the unique race - the row exists now,
				// which is the idempotent success case.
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}

		return $this->buildBoardSubscription($boardId, $actorUid);
	}

	/**
	 * Unsubscribes the actor from the board (removes the row). Idempotent.
	 * Requires READ.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int}
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function unsubscribeBoard(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		$existing = $this->boardSubscriptionMapper->findOne($actorUid, $boardId);
		if ($existing !== null) {
			$this->boardSubscriptionMapper->delete($existing);
		}

		return $this->buildBoardSubscription($boardId, $actorUid);
	}

	/**
	 * The board-level watch block for a user WITHOUT a permission check - the
	 * caller (e.g. the board payload) has already established READ.
	 *
	 * @return array{subscribed: bool, subscribers: string[], count: int}
	 * @throws \OCP\DB\Exception
	 */
	public function buildBoardSubscription(int $boardId, string $actorUid): array {
		$subscribers = $this->boardSubscriptionMapper->findBoardSubscriberUids($boardId);
		return [
			'subscribed' => in_array($actorUid, $subscribers, true),
			'subscribers' => $subscribers,
			'count' => count($subscribers),
		];
	}

	/**
	 * A card was created on a board: fan a "board activity" notification out to
	 * the board's watchers (never the creator, never a watcher who has since
	 * lost READ, and never a watcher outside the card's visibility - most
	 * creates are 'public', but a recurrence spawn inherits its template's
	 * narrower class, #3760). No permission check on the caller -
	 * CardService::create has already gated the create with EDIT.
	 */
	public function notifyBoardCardCreated(int $boardId, Card $card, string $actorUid): void {
		$watchers = $this->boardSubscriptionMapper->findBoardSubscriberUids($boardId);
		if ($watchers === []) {
			return;
		}
		$board = $this->boardMapper->find($boardId);
		// Batched visibility filter first (a public card short-circuits), then
		// the per-watcher READ re-check the fan-out always did.
		foreach ($this->visibilityGuard->filterVisible($board, $card, $watchers) as $uid) {
			if ($uid === $actorUid) {
				continue;
			}
			// Don't leak the card to a watcher who no longer has access.
			try {
				$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
			} catch (NotPermittedException) {
				continue;
			}
			$this->notificationService->notifyBoardActivity($card->getId(), $uid, $actorUid);
		}
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
			// Concurrent insert lost the unique race - the row now exists, which
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

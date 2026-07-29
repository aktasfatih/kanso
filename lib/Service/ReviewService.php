<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReview;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Card review requests. Mirrors {@see AssigneeService}: requesting a review
 * needs EDIT on the card's board and the reviewer must hold READ (a request an
 * outsider can never see is meaningless); a reviewer acts on their OWN review
 * only. Every mutation appends a card-targeted `kanso_changes` row so the tile
 * chip updates over the existing realtime/ETag path (ENTITY_CARD/ACTION_UPDATE
 * - no new Change constant). The done-gate that consumes these rows lives in
 * {@see CardService::move()}, not here.
 */
class ReviewService {
	public function __construct(
		private CardReviewMapper $cardReviewMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private NotificationService $notificationService,
		private ReviewTypeMapper $reviewTypeMapper,
		private BoardService $boardService,
		private CommentService $commentService,
	) {
	}

	/**
	 * The current user's cross-board review feed - every review requested from
	 * them, on a board they can still read, newest first. ACL is enforced by
	 * restricting to the user's readable board set (mirrors SearchService).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findMine(string $uid): array {
		$boardIds = array_map(
			static fn ($board): int => $board->getId(),
			$this->boardService->findAll($uid)
		);
		return $this->cardReviewMapper->findByReviewerInBoards($uid, $boardIds);
	}

	/**
	 * Requests a review from $reviewerUid. A card may hold several reviews per
	 * reviewer as long as they are of different types (untyped counts as one
	 * type) - so a person can carry, e.g., a QA and a Code review at once.
	 * Idempotent per (card, reviewer, type): re-requesting the SAME type is a
	 * no-op; a different type creates a new review.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the reviewer cannot read the board, or the review type is invalid
	 */
	public function requestReview(int $cardId, string $reviewerUid, string $actorUid, ?int $reviewTypeId = null): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		// A reviewer who cannot see the board could never open the card to act.
		if (($this->permissionService->getPermissions($board, $reviewerUid) & PermissionService::PERMISSION_READ) === 0) {
			throw new InvalidInputException('User has no access to this board');
		}

		// A typed request must reference a review type of this card's board.
		if ($reviewTypeId !== null) {
			try {
				$type = $this->reviewTypeMapper->find($reviewTypeId);
			} catch (DoesNotExistException) {
				throw new InvalidInputException('Review type does not exist');
			}
			if ($type->getBoardId() !== $card->getBoardId()) {
				throw new InvalidInputException('Review type belongs to another board');
			}
		}

		if ($this->cardReviewMapper->existsForType($cardId, $reviewerUid, $reviewTypeId ?? 0)) {
			return;
		}

		try {
			$this->cardReviewMapper->insertRequest($cardId, $reviewerUid, $actorUid, $reviewTypeId);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Concurrent PUT lost the check-then-insert race - the request
				// exists, which is the idempotent success case.
				return;
			}
			throw $e;
		}

		$this->notify($card, $actorUid, Change::VERB_REVIEW_REQUESTED);
		$this->notificationService->notifyReviewRequested($cardId, $reviewerUid, $actorUid);
	}

	/**
	 * Withdraws a single review by its row id. Idempotent: an unknown id, or one
	 * belonging to another card, is a no-op.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function withdrawReview(int $cardId, int $reviewId, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		$review = $this->cardReviewMapper->findById($reviewId);
		if ($review === null || $review->getCardId() !== $cardId) {
			return;
		}
		$reviewerUid = $review->getReviewer();
		$this->cardReviewMapper->delete($review);

		$this->notify($card, $actorUid);
		$this->notificationService->dismissReviewRequested($cardId, $reviewerUid);
	}

	/**
	 * The reviewer records their verdict on their OWN review (targeted by row
	 * id), and only to approved / changes_requested. When requesting changes
	 * with a $reason, the reason is posted as a card comment by the reviewer so
	 * subscribers are notified and it lands in the discussion.
	 *
	 * @throws DoesNotExistException if the card, its board, or the review does not exist
	 * @throws NotPermittedException if the actor is not the reviewer or cannot read the board
	 * @throws InvalidInputException if $state is not a settable verdict
	 */
	public function setState(int $cardId, int $reviewId, string $state, string $actorUid, ?string $reason = null): void {
		if (!in_array($state, CardReview::settableStates(), true)) {
			throw new InvalidInputException('Invalid review state');
		}

		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());

		$review = $this->cardReviewMapper->findById($reviewId);
		if ($review === null || $review->getCardId() !== $cardId) {
			throw new DoesNotExistException('Review ' . $reviewId . ' does not exist on card ' . $cardId);
		}
		if ($actorUid !== $review->getReviewer()) {
			throw new NotPermittedException('Only the reviewer may set their review state');
		}
		// The reviewer must still be able to read the board to act on the review.
		if (($this->permissionService->getPermissions($board, $actorUid) & PermissionService::PERMISSION_READ) === 0) {
			throw new NotPermittedException('User has no access to this board');
		}

		$reason = $reason !== null ? trim($reason) : null;

		// Record the verdict FIRST so it always lands - a reviewer only needs READ
		// to review, so the verdict must not depend on the (EDIT-gated) comment.
		if ($review->getState() !== $state) {
			$review->setState($state);
			$this->cardReviewMapper->update($review);

			$this->notify($card, $actorUid, Change::VERB_REVIEW_VERDICT);
			// The reviewer has acted - clear their pending "review requested" bell.
			$this->notificationService->dismissReviewRequested($cardId, $review->getReviewer());
		}

		// A "request changes" reason becomes a comment by the reviewer (even if the
		// state was unchanged - they can add further reasons). Best-effort: a
		// READ-only reviewer who can't comment still keeps their verdict.
		if ($state === CardReview::STATE_CHANGES_REQUESTED && $reason !== null && $reason !== '') {
			try {
				$this->commentService->addComment($cardId, '**Requested changes:** ' . $reason, null, $actorUid);
			} catch (\Throwable) {
				// Reviewer lacks EDIT to comment - the verdict still stands.
			}
		}
	}

	private function notify(Card $card, string $actorUid, ?int $verb = null): void {
		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$actorUid,
			verb: $verb,
		);
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

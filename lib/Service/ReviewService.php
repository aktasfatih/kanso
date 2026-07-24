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
 * — no new Change constant). The done-gate that consumes these rows lives in
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
	) {
	}

	/**
	 * Requests a review from $reviewerUid. Idempotent: re-requesting an existing
	 * reviewer is a no-op (their current state is kept) and writes no change row.
	 * $reviewTypeId is applied only on the INITIAL request — a re-request of an
	 * existing review ignores it (withdraw + re-request to change the type). An
	 * invalid type is still rejected, even on a re-request.
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

		if ($this->cardReviewMapper->exists($cardId, $reviewerUid)) {
			return;
		}

		try {
			$this->cardReviewMapper->insertRequest($cardId, $reviewerUid, $actorUid, $reviewTypeId);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Concurrent PUT lost the check-then-insert race — the request
				// exists, which is the idempotent success case.
				return;
			}
			throw $e;
		}

		$this->notify($card, $actorUid);
		$this->notificationService->notifyReviewRequested($cardId, $reviewerUid, $actorUid);
	}

	/**
	 * Withdraws the review request from $reviewerUid. Idempotent: withdrawing an
	 * absent request is a no-op and writes no change row.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function withdrawReview(int $cardId, string $reviewerUid, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		if ($this->cardReviewMapper->deleteReview($cardId, $reviewerUid) === 0) {
			return;
		}

		$this->notify($card, $actorUid);
		$this->notificationService->dismissReviewRequested($cardId, $reviewerUid);
	}

	/**
	 * The reviewer records their verdict on their OWN review. Only the reviewer
	 * may set their state, and only to approved / changes_requested.
	 *
	 * @throws DoesNotExistException if the card, its board, or the review does not exist
	 * @throws NotPermittedException if the actor is not the reviewer or cannot read the board
	 * @throws InvalidInputException if $state is not a settable verdict
	 */
	public function setState(int $cardId, string $reviewerUid, string $state, string $actorUid): void {
		if ($actorUid !== $reviewerUid) {
			throw new NotPermittedException('Only the reviewer may set their review state');
		}
		if (!in_array($state, CardReview::settableStates(), true)) {
			throw new InvalidInputException('Invalid review state');
		}

		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		// The reviewer must still be able to read the board to act on the review.
		if (($this->permissionService->getPermissions($board, $actorUid) & PermissionService::PERMISSION_READ) === 0) {
			throw new NotPermittedException('User has no access to this board');
		}

		$review = $this->cardReviewMapper->findReview($cardId, $reviewerUid);
		if ($review === null) {
			throw new DoesNotExistException('No review requested from this user on card ' . $cardId);
		}
		if ($review->getState() === $state) {
			return;
		}

		$review->setState($state);
		$this->cardReviewMapper->update($review);

		$this->notify($card, $actorUid);
		// The reviewer has acted — clear their pending "review requested" bell.
		$this->notificationService->dismissReviewRequested($cardId, $reviewerUid);
	}

	private function notify(Card $card, string $actorUid): void {
		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$actorUid
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

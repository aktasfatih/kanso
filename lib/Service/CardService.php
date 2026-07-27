<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Card CRUD and moves. Every mutation appends a row to the `kanso_changes`
 * log in the same flow (see BoardService). Ordering uses fractional sort
 * keys: creation appends to the bottom of the stack and a move rewrites a
 * single card row — no sibling renumbering, ever.
 */
class CardService {
	private const MAX_TITLE_LENGTH = 100;

	public function __construct(
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private SortKeyService $sortKeyService,
		private CardReviewMapper $cardReviewMapper,
		private IDBConnection $db,
		private SubscriptionService $subscriptionService,
	) {
	}

	/**
	 * Full card detail including the description.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function find(int $id, string $uid): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		return $card;
	}

	/**
	 * Creates a card at the bottom of the stack.
	 *
	 * @throws DoesNotExistException if the stack or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title
	 * @throws \OverflowException if the appended sort key would overflow (stack needs a rebalance)
	 *                            or a concurrent create keeps colliding after one retry
	 */
	public function create(int $stackId, string $title, string $uid): Card {
		$stack = $this->loadStack($stackId);
		$board = $this->loadBoard($stack->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$title = $this->validateTitle($title);
		$now = time();

		// Append to the bottom of the stack. A concurrent create into the same
		// stack can derive the same append key; the (stack_id, sort_key,
		// deleted_at) unique index rejects the loser, so re-read the now-current
		// last card and re-derive once before giving up with a retryable 409.
		for ($attempt = 0; ; $attempt++) {
			$lastCard = $this->cardMapper->findLastInStack($stackId);
			$sortKey = $lastCard === null
				? $this->sortKeyService->initial()
				: $this->sortKeyService->after($lastCard->getSortKey());

			$card = new Card();
			$card->setBoardId($stack->getBoardId());
			$card->setStackId($stackId);
			$card->setTitle($title);
			$card->setSortKey($sortKey);
			// Creating a card directly in a done-role stack stamps it done, and in
			// an in-progress-role stack stamps it started — matching move()'s
			// status-automation for a dragged-in card.
			$card->setDoneAt($stack->getRole() === Stack::ROLE_DONE ? $now : 0);
			$card->setStartedAt($stack->getRole() === Stack::ROLE_IN_PROGRESS ? $now : 0);
			$card->setArchived(false);
			$card->setOwner($uid);
			$card->setCreatedAt($now);
			$card->setLastModified($now);
			$card->setDeletedAt(0);

			try {
				$card = $this->cardMapper->insert($card);
				break;
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				if ($attempt >= 1) {
					throw new \OverflowException('sort key conflict on create after retry', 0, $e);
				}
			}
		}

		$this->changeNotifier->notify(
			$stack->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_CREATE,
			$uid
		);

		// Fan a "new card on a board you watch" notification out to board
		// watchers. Best-effort — a notification hiccup must never fail the
		// create (the card + its change row are already committed).
		try {
			$this->subscriptionService->notifyBoardCardCreated($stack->getBoardId(), $card->getId(), $uid);
		} catch (\Throwable) {
			// Ignore — board-activity fan-out is a non-critical side effect.
		}

		return $card;
	}

	/**
	 * Updates the given fields (null = leave unchanged). An empty duedate
	 * string clears the due date; an empty description string clears the
	 * description. done=true stamps done_at only once (idempotent),
	 * done=false clears it.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title or duedate
	 */
	public function update(
		int $id,
		?string $title,
		?string $description,
		?string $duedate,
		?bool $done,
		?bool $archived,
		string $uid,
		?int $priority = null,
		?string $startDate = null,
		?string $status = null,
	): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		if ($title !== null) {
			$card->setTitle($this->validateTitle($title));
		}
		if ($priority !== null) {
			if ($priority < Card::PRIORITY_NONE || $priority > Card::PRIORITY_URGENT) {
				throw new InvalidInputException('Priority must be between 0 and 4');
			}
			$card->setPriority($priority);
		}
		if ($description !== null) {
			$card->setDescription($description);
		}
		if ($duedate !== null) {
			$card->setDuedate($this->parseDuedate($duedate));
		}
		if ($startDate !== null) {
			// Same wire format + parsing as duedate; '' clears it.
			$card->setStartDate($this->parseDuedate($startDate));
		}
		if ($done !== null) {
			if ($done) {
				if ($card->getDoneAt() === 0) {
					$card->setDoneAt(time());
				}
			} else {
				$card->setDoneAt(0);
			}
		}
		if ($archived !== null) {
			$card->setArchived($archived);
		}
		if ($status !== null) {
			$this->applyStatus($card, $status);
		}

		$now = time();
		$card->setLastModified($now);
		$card = $this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$id,
			Change::ACTION_UPDATE,
			$uid
		);

		// Completing (or archiving) the last open child auto-completes the parent.
		$this->maybeCompleteParent($card, $uid);

		return $card;
	}

	/**
	 * Soft-deletes the card (sets deleted_at). Any children are first detached
	 * (parent_card_id cleared) so no live card is left pointing at a hidden
	 * parent — the one-level hierarchy stays self-healing. The parent's DELETE
	 * change row bumps the board ETag, so clients refetch and see the detached
	 * children; the per-child clears ride along without their own change rows.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 */
	public function delete(int $id, string $uid): void {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$now = time();

		foreach ($this->cardMapper->findChildren($id) as $child) {
			$child->setParentCardId(null);
			$child->setLastModified($now);
			$this->cardMapper->update($child);
		}

		$card->setDeletedAt($now);
		$card->setLastModified($now);
		$this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$id,
			Change::ACTION_DELETE,
			$uid
		);
	}

	/**
	 * Moves the card inside its board: into $targetStackId, directly after
	 * $afterCardId, or to the top of the target stack when $afterCardId is
	 * null. The transaction makes the card update and its change row atomic
	 * (rollback on failure).
	 *
	 * Concurrent moves are NOT serialized: two moves into the same gap can each
	 * read the same neighbours under READ COMMITTED and derive the same key.
	 * The composite unique index on (stack_id, sort_key, deleted_at) rejects the
	 * loser's UPDATE, so we re-read the neighbours and re-derive once; if it
	 * still collides, a retryable 409 (\OverflowException → rebalance_required)
	 * is surfaced rather than persisting a duplicate key.
	 *
	 * @throws DoesNotExistException if the card, its board or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board, or the review gate blocks a review-role → done-role move with unapproved reviews
	 * @throws InvalidInputException if the target stack is on another board or $afterCardId is unusable
	 * @throws \OverflowException if the new sort key would overflow (stack needs a rebalance) or keeps colliding after one retry
	 */
	public function move(int $id, int $targetStackId, ?int $afterCardId, string $uid): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$targetStack = $this->loadStack($targetStackId);
		if ($targetStack->getBoardId() !== $card->getBoardId()) {
			throw new InvalidInputException('Cannot move a card to a stack on another board');
		}

		// Source stack role for the done-automation. A move within the same
		// stack keeps the target's role — done state then stays put.
		$sourceStack = $targetStackId === $card->getStackId()
			? $targetStack
			: $this->stackMapper->find($card->getStackId());

		// Review gate: a card leaving a review-role stack for a done-role stack
		// may not be marked done while any requested review is still unapproved.
		// A board with no review-role stack never trips this — the gate is
		// naturally opt-in via stack roles. Pure precondition, checked once
		// before the write/retry loop so it fails fast without a DB write.
		if ($sourceStack->getRole() === Stack::ROLE_REVIEW
			&& $targetStack->getRole() === Stack::ROLE_DONE
			&& $this->cardReviewMapper->hasUnapprovedReviews($id)) {
			throw new NotPermittedException('All requested reviews must be approved before this card can be marked done');
		}

		for ($attempt = 0; ; $attempt++) {
			$afterCard = $afterCardId === null
				? null
				: $this->loadAfterCard($afterCardId, $targetStackId, $id);
			try {
				$moved = $this->persistMove($card, $targetStackId, $afterCard, $sourceStack, $targetStack, $uid);
				// Moving the last open child into a done-role stack (which stamps
				// it done) auto-completes the parent.
				$this->maybeCompleteParent($moved, $uid);
				return $moved;
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				if ($attempt >= 1) {
					throw new \OverflowException('sort key conflict on move after retry', 0, $e);
				}
				// Discard the mutations from the rolled-back attempt before retrying.
				$card = $this->loadCard($id);
			}
		}
	}

	/**
	 * Persists a move inside a transaction: derive the key, update the single
	 * card row and append the change row atomically (rollback on failure). A
	 * unique-constraint violation from a concurrent move into the same gap
	 * propagates to {@see self::move()} for a re-derive/retry.
	 *
	 * @throws \OCP\DB\Exception on a DB error (including the unique-key race)
	 * @throws \OverflowException if the derived key would overflow — rebalance needed
	 */
	private function persistMove(
		Card $card,
		int $targetStackId,
		?Card $afterCard,
		Stack $sourceStack,
		Stack $targetStack,
		string $uid,
	): Card {
		$this->db->beginTransaction();
		try {
			$sortKey = $this->deriveMoveKey($targetStackId, $afterCard);

			$now = time();
			$card->setStackId($targetStackId);
			$card->setSortKey($sortKey);
			$card->setLastModified($now);
			$this->applyDoneAutomation($card, $sourceStack, $targetStack, $now);
			$card = $this->cardMapper->update($card);

			$this->changeNotifier->notify(
				$card->getBoardId(),
				Change::ENTITY_CARD,
				$card->getId(),
				Change::ACTION_MOVE,
				$uid
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $card;
	}

	/**
	 * Sets ($parentCardId given) or clears ($parentCardId null) the card's
	 * parent. Requires EDIT on the card's board. The hierarchy is ONE level and
	 * same-board only:
	 *   - the parent must be on the same board and not the card itself;
	 *   - the parent must not itself have a parent (no grandparents);
	 *   - a card that already has children may not become a child.
	 * A no-op (parent already as requested) writes no change row.
	 *
	 * The checks and the write are not serialized (like {@see self::move()}):
	 * two concurrent setParent calls could interleave to build a 2-level chain
	 * (set A's parent = B while B's parent is set = C, each seeing the other's
	 * pre-state). Accepted for now — cosmetic, a subsequent edit repairs it, no
	 * data loss; a DB-level guard is the planned mitigation.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException if the parent is invalid (self, other board, deleted, or the one-level rule is violated)
	 */
	public function setParent(int $id, ?int $parentCardId, string $uid): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		if ($parentCardId === null) {
			if ($card->getParentCardId() === null) {
				return $card;
			}
			$card->setParentCardId(null);
		} else {
			if ($parentCardId === $id) {
				throw new InvalidInputException('A card cannot be its own parent');
			}
			$parent = $this->loadParentCard($parentCardId);
			if ($parent->getBoardId() !== $card->getBoardId()) {
				throw new InvalidInputException('Parent card must be on the same board');
			}
			if ($parent->getParentCardId() !== null) {
				throw new InvalidInputException('Cards can only be nested one level deep');
			}
			if ($this->cardMapper->hasChildren($id)) {
				throw new InvalidInputException('A card that has children cannot become a child');
			}
			if ($card->getParentCardId() === $parentCardId) {
				return $card;
			}
			$card->setParentCardId($parentCardId);
		}

		$card->setLastModified(time());
		$card = $this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$id,
			Change::ACTION_UPDATE,
			$uid
		);

		return $card;
	}

	/**
	 * Loads the prospective parent card, mapping absence/deletion to invalid
	 * input (the client picked a card that is gone) rather than a 404 on the
	 * card being edited.
	 *
	 * @throws InvalidInputException
	 */
	private function loadParentCard(int $parentCardId): Card {
		try {
			$parent = $this->cardMapper->find($parentCardId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Parent card ' . $parentCardId . ' does not exist');
		}
		if ($parent->getDeletedAt() > 0) {
			throw new InvalidInputException('Parent card ' . $parentCardId . ' does not exist');
		}
		return $parent;
	}

	/**
	 * Done-automation for a move: entering a done-role stack stamps the card
	 * done (once — an already-done card is left alone); leaving a done-role
	 * stack for a non-done one clears it. The done_at change rides the same
	 * card UPDATE as the move, so no extra change row is written. Unconditional
	 * for v1 — a done-role stack stamping done is the expected default.
	 */
	private function applyDoneAutomation(Card $card, Stack $sourceStack, Stack $targetStack, int $now): void {
		$sourceDone = $sourceStack->getRole() === Stack::ROLE_DONE;
		$targetDone = $targetStack->getRole() === Stack::ROLE_DONE;

		if ($targetDone) {
			if ($card->getDoneAt() === 0) {
				$card->setDoneAt($now);
			}
		} elseif ($sourceDone) {
			$card->setDoneAt(0);
		}

		// Started-automation: entering an in-progress-role stack stamps the card
		// "started" (status → In progress). Forward-only — never unset on leaving
		// — and never over a done card. Rides the same UPDATE as the move.
		if ($targetStack->getRole() === Stack::ROLE_IN_PROGRESS
			&& ($card->getStartedAt() ?? 0) === 0
			&& ($card->getDoneAt() ?? 0) === 0) {
			$card->setStartedAt($now);
		}
	}

	/**
	 * Sets the card's derived status directly (the card-view control). Status is
	 * two timestamps: done_at (Done) and started_at (In progress) — this is the
	 * one place a card can be moved BACKWARD (e.g. Done → In progress), unlike
	 * the forward-only move automation.
	 *
	 * @throws InvalidInputException on an unknown status
	 */
	private function applyStatus(Card $card, string $status): void {
		$now = time();
		switch ($status) {
			case 'done':
				if ($card->getDoneAt() === 0) {
					$card->setDoneAt($now);
				}
				break;
			case 'in_progress':
				$card->setDoneAt(0);
				if (($card->getStartedAt() ?? 0) === 0) {
					$card->setStartedAt($now);
				}
				break;
			case 'not_started':
				$card->setDoneAt(0);
				$card->setStartedAt(0);
				break;
			default:
				throw new InvalidInputException('Unknown status: ' . $status);
		}
	}

	/**
	 * Auto-completes a parent card once ALL of its children are resolved (done
	 * or archived) — the "all children done → parent done" workflow. Called from
	 * a CHILD's update()/move() after it persists; a card with no parent is a
	 * fast no-op (the common case). Forward-only for v1: it never RE-OPENS a
	 * parent (a parent a human marked done is left alone), and since the
	 * hierarchy is one level (a parent has no parent) stamping it done cannot
	 * cascade — no loop guard needed.
	 */
	private function maybeCompleteParent(Card $child, string $uid): void {
		$parentId = $child->getParentCardId();
		if ($parentId === null) {
			return;
		}
		try {
			$parent = $this->cardMapper->find($parentId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($parent->getDeletedAt() > 0 || $parent->getDoneAt() > 0) {
			return;
		}

		foreach ($this->cardMapper->findChildren($parentId) as $sibling) {
			if ($sibling->getDoneAt() === 0 && !$sibling->getArchived()) {
				return; // an unresolved child remains
			}
		}

		$now = time();
		$parent->setDoneAt($now);
		$parent->setLastModified($now);
		$this->cardMapper->update($parent);

		$this->changeNotifier->notify(
			$parent->getBoardId(),
			Change::ENTITY_CARD,
			$parentId,
			Change::ACTION_UPDATE,
			$uid
		);
	}

	/**
	 * New sort key for a card landing in $targetStackId after $afterCard
	 * (null = top of the stack). The moved card may itself be one of the
	 * neighbours — its still-current key then bounds the result, which is
	 * fine: the derived key remains strictly ordered.
	 *
	 * @throws \OverflowException if the key would overflow — rebalance needed
	 */
	private function deriveMoveKey(int $targetStackId, ?Card $afterCard): string {
		if ($afterCard !== null) {
			$next = $this->cardMapper->findNextInStack($targetStackId, $afterCard->getSortKey());
			return $next === null
				? $this->sortKeyService->after($afterCard->getSortKey())
				: $this->sortKeyService->between($afterCard->getSortKey(), $next->getSortKey());
		}
		$first = $this->cardMapper->findFirstInStack($targetStackId);
		return $first === null
			? $this->sortKeyService->initial()
			: $this->sortKeyService->before($first->getSortKey());
	}

	/**
	 * Loads and validates the move anchor. Any unusable anchor (missing,
	 * deleted, wrong stack, the moved card itself) is invalid input — the
	 * client's picture of the stack is stale, not the moved card's fault.
	 *
	 * @throws InvalidInputException
	 */
	private function loadAfterCard(int $afterCardId, int $targetStackId, int $movedCardId): Card {
		if ($afterCardId === $movedCardId) {
			throw new InvalidInputException('afterCardId must not be the moved card itself');
		}
		try {
			$afterCard = $this->cardMapper->find($afterCardId);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Card ' . $afterCardId . ' does not exist');
		}
		if ($afterCard->getDeletedAt() > 0) {
			throw new InvalidInputException('Card ' . $afterCardId . ' does not exist');
		}
		if ($afterCard->getStackId() !== $targetStackId) {
			throw new InvalidInputException('Card ' . $afterCardId . ' is not in the target stack');
		}
		return $afterCard;
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
	 * @throws DoesNotExistException if the stack does not exist or is deleted
	 */
	private function loadStack(int $id): Stack {
		$stack = $this->stackMapper->find($id);
		if ($stack->getDeletedAt() > 0) {
			throw new DoesNotExistException('Stack ' . $id . ' is deleted');
		}
		return $stack;
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

	/**
	 * @throws InvalidInputException
	 */
	private function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidInputException('Title must not be empty');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidInputException(
				'Title must not exceed ' . self::MAX_TITLE_LENGTH . ' characters'
			);
		}
		return $title;
	}

	/**
	 * Strict ISO 8601 due dates, normalized to UTC. The empty string clears
	 * the due date. Two accepted shapes: RFC 3339 without fractional seconds
	 * (2026-07-22T12:00:00Z / +02:00) and with milliseconds
	 * (2026-07-22T12:00:00.000Z) — the latter is what JS Date.toISOString()
	 * produces.
	 *
	 * @throws InvalidInputException on any other shape
	 */
	private function parseDuedate(string $duedate): ?\DateTime {
		if ($duedate === '') {
			return null;
		}
		$parsed = \DateTime::createFromFormat(\DateTimeInterface::ATOM, $duedate)
			?: \DateTime::createFromFormat('Y-m-d\TH:i:s.vP', $duedate);
		// createFromFormat rolls over out-of-range components (2026-02-30
		// becomes March 2nd) and only records it in getLastErrors — reject
		// those too, or clients get a silently wrong date back.
		$errors = \DateTime::getLastErrors();
		if ($parsed === false
			|| ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new InvalidInputException(
				'Due date must be an ISO 8601 datetime like 2026-07-22T12:00:00Z'
			);
		}
		$parsed->setTimezone(new \DateTimeZone('UTC'));
		return $parsed;
	}
}

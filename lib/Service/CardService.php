<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\EstimateScale;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Card CRUD and moves. Every mutation appends a row to the `kanso_changes`
 * log in the same flow (see BoardService). Ordering uses fractional sort
 * keys: creation appends to the bottom of the stack and a move rewrites a
 * single card row - no sibling renumbering, ever.
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
		private AutomationService $automationService,
		private MentionService $mentionService,
		private LabelService $labelService,
		private ChecklistService $checklistService,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private ChecklistItemMapper $checklistItemMapper,
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

		// Default: append to the bottom of the stack. When the board opts in,
		// place the new card at the TOP instead (before the current head). A
		// concurrent create into the same stack can derive the same key; the
		// (stack_id, sort_key, deleted_at) unique index rejects the loser, so
		// re-read the now-current neighbour and re-derive once before giving up
		// with a retryable 409.
		$onTop = $board->getNewCardsOnTop() === true;
		for ($attempt = 0; ; $attempt++) {
			if ($onTop) {
				$firstCard = $this->cardMapper->findFirstInStack($stackId);
				$sortKey = $firstCard === null
					? $this->sortKeyService->initial()
					: $this->sortKeyService->before($firstCard->getSortKey());
			} else {
				$lastCard = $this->cardMapper->findLastInStack($stackId);
				$sortKey = $lastCard === null
					? $this->sortKeyService->initial()
					: $this->sortKeyService->after($lastCard->getSortKey());
			}

			$card = new Card();
			$card->setBoardId($stack->getBoardId());
			$card->setStackId($stackId);
			$card->setTitle($title);
			$card->setSortKey($sortKey);
			// Creating a card directly in a role-bearing stack adopts that
			// column's status - matching move()'s status-automation for a
			// dragged-in card. Done-role → done; in-progress/review-role →
			// started; backlog/to-do/none → not started (both left at 0).
			$card->setDoneAt($stack->getRole() === Stack::ROLE_DONE ? $now : 0);
			$card->setStartedAt(
				in_array($stack->getRole(), [Stack::ROLE_IN_PROGRESS, Stack::ROLE_REVIEW], true) ? $now : 0,
			);
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
			$uid,
			verb: Change::VERB_CREATED,
		);

		// Fan a "new card on a board you watch" notification out to board
		// watchers. Best-effort - a notification hiccup must never fail the
		// create (the card + its change row are already committed).
		try {
			$this->subscriptionService->notifyBoardCardCreated($stack->getBoardId(), $card->getId(), $uid);
		} catch (\Throwable) {
			// Ignore - board-activity fan-out is a non-critical side effect.
		}

		return $card;
	}

	/**
	 * Duplicates a card's CONTENT into $targetStackId (same board or another
	 * board the actor can EDIT). What is cloned: title (suffixed " (copy)"),
	 * description, priority, status (started/done timestamps), estimate, labels
	 * and checklist items. What is NOT cloned: comments, activity/history,
	 * relations, subscriptions, parent/children and assignees - a copy is a
	 * fresh, standalone card. The new card is appended to the target stack via
	 * {@see self::create()} (fresh id + sort key + its own change row) and the
	 * per-field/label/checklist writes reuse the existing services.
	 *
	 * Labels are board-scoped:
	 *   - same-board copy re-assigns the source label ids directly;
	 *   - cross-board copy maps each source label to a target-board label with
	 *     the SAME title (case-insensitive) AND color, and DROPS any that has no
	 *     such twin (labels are never auto-created on the target).
	 *
	 * Estimate is likewise board-scoped: a source estimate token that the target
	 * board's scale does not allow is dropped (the copy simply has no estimate).
	 *
	 * @throws DoesNotExistException if the source card, either board or the target stack does not exist or is deleted
	 * @throws NotPermittedException if the actor lacks EDIT on the source OR the target board
	 * @throws InvalidInputException if the source title is invalid
	 * @throws \OverflowException if the appended sort key would overflow (target stack needs a rebalance)
	 */
	public function copy(int $id, int $targetStackId, string $uid): Card {
		// Load the source with its description and assert EDIT on its board (read
		// access to clone the content is gated at EDIT per the card's rule, not
		// the weaker READ that find() would use).
		$source = $this->loadCard($id);
		$sourceBoard = $this->loadBoard($source->getBoardId());
		$this->permissionService->assertPermission($sourceBoard, $uid, PermissionService::PERMISSION_EDIT);

		$targetStack = $this->loadStack($targetStackId);
		$targetBoard = $this->loadBoard($targetStack->getBoardId());
		// create() also asserts EDIT on the target board, but assert it up-front
		// so a permission failure never leaves a half-copied card behind.
		$this->permissionService->assertPermission($targetBoard, $uid, PermissionService::PERMISSION_EDIT);

		$sameBoard = $sourceBoard->getId() === $targetBoard->getId();

		// 1. Create the shell at the bottom of the target stack (fresh id + key +
		//    change row + board-watcher fan-out - all reused from create()).
		$copy = $this->create($targetStackId, $this->copyTitle($source->getTitle()), $uid);

		// 2. Carry the scalar content the create() shell does not set. Estimate is
		//    only kept when the TARGET board's scale accepts the token.
		$copy->setDescription($source->getDescription());
		$copy->setPriority($source->getPriority());
		$copy->setStartedAt($source->getStartedAt());
		$copy->setDoneAt($source->getDoneAt());
		$estimate = $source->getEstimate();
		if ($estimate !== null && EstimateScale::allows($targetBoard->getEstimateScale(), $estimate)) {
			$copy->setEstimate($estimate);
		}
		$copy->setLastModified(time());
		$copy = $this->cardMapper->update($copy);

		// 3. Labels - same-board re-assigns ids directly; cross-board maps by
		//    title+color to the target board's labels (unmatched ones drop).
		$this->copyLabels($source, $copy, $sameBoard, $targetBoard, $uid);

		// 4. Checklist items, in display order (reuses ChecklistService::addItem,
		//    which appends and writes its own change row). The done state rides
		//    the same insert, so a done item is a single write - not add+toggle.
		foreach ($this->checklistItemMapper->findByCard($id) as $item) {
			$this->checklistService->addItem($copy->getId(), $item->getTitle(), $uid, $item->getDone());
		}

		return $copy;
	}

	/**
	 * Re-labels the copy. Same-board: re-assign every source label id directly.
	 * Cross-board: for each source label, find a target-board label with the same
	 * title (case-insensitive, trimmed) AND color and assign that; drop the rest.
	 */
	private function copyLabels(Card $source, Card $copy, bool $sameBoard, Board $targetBoard, string $uid): void {
		$sourceLabelIds = $this->cardLabelMapper->findLabelIdsByCard($source->getId());
		if ($sourceLabelIds === []) {
			return;
		}

		if ($sameBoard) {
			foreach ($sourceLabelIds as $labelId) {
				$this->labelService->assign($copy->getId(), $labelId, $uid);
			}
			return;
		}

		// Index the target board's labels by a normalized (title|color) key so a
		// source label maps to at most one target twin.
		$targetByKey = [];
		foreach ($this->labelMapper->findByBoard($targetBoard->getId()) as $targetLabel) {
			$targetByKey[$this->labelKey($targetLabel)] ??= $targetLabel->getId();
		}

		foreach ($sourceLabelIds as $labelId) {
			try {
				$sourceLabel = $this->labelMapper->find($labelId);
			} catch (DoesNotExistException) {
				continue;
			}
			$twinId = $targetByKey[$this->labelKey($sourceLabel)] ?? null;
			if ($twinId !== null) {
				$this->labelService->assign($copy->getId(), $twinId, $uid);
			}
			// else: no title+color twin on the target board - drop this label.
		}
	}

	/**
	 * Normalized identity of a label for cross-board matching: trimmed,
	 * lower-cased title joined with the color (labels compare equal only when
	 * BOTH match).
	 */
	private function labelKey(Label $label): string {
		return mb_strtolower(trim((string)$label->getTitle())) . '|' . (string)$label->getColor();
	}

	/**
	 * Derives the copy's title, suffixing " (copy)" while respecting the
	 * MAX_TITLE_LENGTH cap (the suffix wins - the base is truncated to fit).
	 */
	private function copyTitle(string $title): string {
		$suffix = ' (copy)';
		$title = trim($title);
		if (mb_strlen($title) + mb_strlen($suffix) > self::MAX_TITLE_LENGTH) {
			// Re-trim after truncation so we never render "…word (copy)" with a
			// doubled space at the seam.
			$title = rtrim(mb_substr($title, 0, self::MAX_TITLE_LENGTH - mb_strlen($suffix)));
		}
		return $title . $suffix;
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
		?string $estimate = null,
		?bool $allDay = null,
	): Card {
		$card = $this->loadCard($id);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		if ($title !== null) {
			$card->setTitle($this->validateTitle($title));
		}
		if ($estimate !== null) {
			// '' clears the estimate; any other value must belong to the board's
			// scale (which rejects everything when the scale is 'none').
			if ($estimate === '') {
				$card->setEstimate(null);
			} elseif (EstimateScale::allows($board->getEstimateScale(), $estimate)) {
				$card->setEstimate($estimate);
			} else {
				throw new InvalidInputException('Estimate is not valid for this board\'s scale');
			}
		}
		if ($priority !== null) {
			if ($priority < Card::PRIORITY_NONE || $priority > Card::PRIORITY_URGENT) {
				throw new InvalidInputException('Priority must be between 0 and 4');
			}
			$card->setPriority($priority);
		}
		$descriptionChanged = false;
		if ($description !== null && $description !== $card->getDescription()) {
			$card->setDescription($description);
			$descriptionChanged = true;
		}
		if ($duedate !== null) {
			$parsedDue = $this->parseDuedate($duedate);
			$card->setDuedate($parsedDue);
			// Clearing the due date also clears the all-day flag (no date to qualify).
			if ($parsedDue === null) {
				$card->setAllDay(false);
			}
		}
		if ($allDay !== null) {
			$card->setAllDay($allDay);
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
			$uid,
			verb: Change::VERB_UPDATED,
		);

		// A new @mention in the description pings + auto-subscribes readable-board
		// participants (only when the description actually changed).
		if ($descriptionChanged) {
			$this->mentionService->handleMentions($id, $board, (string)$description, $uid);
		}

		// Completing (or archiving) the last open child auto-completes the parent.
		$this->maybeCompleteParent($card, $uid);

		return $card;
	}

	/**
	 * Soft-deletes the card (sets deleted_at). Any children are first detached
	 * (parent_card_id cleared) so no live card is left pointing at a hidden
	 * parent - the one-level hierarchy stays self-healing. The parent's DELETE
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
			$uid,
			verb: Change::VERB_DELETED,
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
		// stack keeps the target's role - done state then stays put.
		$sourceStack = $targetStackId === $card->getStackId()
			? $targetStack
			: $this->stackMapper->find($card->getStackId());

		// Review gate: a card leaving a review-role stack for a done-role stack
		// may not be marked done while any requested review is still unapproved.
		// A board with no review-role stack never trips this - the gate is
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
				// A move into a DIFFERENT stack fires the board's card_entered_role
				// automation rules (best-effort; runs after the move is committed).
				if ($targetStackId !== $sourceStack->getId()) {
					$this->automationService->runCardEnteredRole($moved, $targetStack->getRole(), $uid);
				}
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
	 * @throws \OverflowException if the derived key would overflow - rebalance needed
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

			// Write the change row inside the transaction (delta-sync source of
			// truth), but DEFER the realtime push until after commit - otherwise a
			// client could refetch pre-commit state, or get an event for a move
			// that the unique-key retry then rolls back.
			$this->changeNotifier->notify(
				$card->getBoardId(),
				Change::ENTITY_CARD,
				$card->getId(),
				Change::ACTION_MOVE,
				$uid,
				false,
				Change::VERB_MOVED,
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast the move.
		$this->changeNotifier->emitPush($card->getBoardId());

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
	 * pre-state). Accepted for now - cosmetic, a subsequent edit repairs it, no
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
			$uid,
			verb: Change::VERB_UPDATED,
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
	 * Status-automation for a move: a column's role IS its status, so dragging a
	 * card into a column adopts that column's lifecycle stage -
	 *   Backlog / To do → Not started (both timestamps cleared),
	 *   In progress / Review → In progress (started stamped, done cleared),
	 *   Done → Done (done stamped once, keeping an already-done card's time).
	 * A role-less column (ROLE_NONE) carries no status and leaves the card as-is,
	 * and a reorder within the same column never rewrites the status. The
	 * timestamp changes ride the same card UPDATE as the move, so no extra change
	 * row is written.
	 */
	private function applyDoneAutomation(Card $card, Stack $sourceStack, Stack $targetStack, int $now): void {
		// A reorder within the same column must never rewrite the card's status -
		// only entering a DIFFERENT column applies that column's role.
		if ($sourceStack->getId() === $targetStack->getId()) {
			return;
		}

		switch ($targetStack->getRole()) {
			case Stack::ROLE_BACKLOG:
			case Stack::ROLE_TODO:
				// Not started - clear both timestamps.
				$card->setStartedAt(0);
				$card->setDoneAt(0);
				break;
			case Stack::ROLE_IN_PROGRESS:
			case Stack::ROLE_REVIEW:
				// In progress - started, not done.
				$card->setDoneAt(0);
				if (($card->getStartedAt() ?? 0) === 0) {
					$card->setStartedAt($now);
				}
				break;
			case Stack::ROLE_DONE:
				// Done - stamp once (an already-done card keeps its time).
				if ($card->getDoneAt() === 0) {
					$card->setDoneAt($now);
				}
				break;
			case Stack::ROLE_NONE:
			default:
				// No associated status - leave the card's status as-is.
				break;
		}
	}

	/**
	 * Sets the card's derived status directly (the card-view control). Status is
	 * two timestamps: done_at (Done) and started_at (In progress) - this is the
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
	 * or archived) - the "all children done → parent done" workflow. Called from
	 * a CHILD's update()/move() after it persists; a card with no parent is a
	 * fast no-op (the common case). Forward-only for v1: it never RE-OPENS a
	 * parent (a parent a human marked done is left alone), and since the
	 * hierarchy is one level (a parent has no parent) stamping it done cannot
	 * cascade - no loop guard needed.
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
			$uid,
			verb: Change::VERB_UPDATED,
		);
	}

	/**
	 * New sort key for a card landing in $targetStackId after $afterCard
	 * (null = top of the stack). The moved card may itself be one of the
	 * neighbours - its still-current key then bounds the result, which is
	 * fine: the derived key remains strictly ordered.
	 *
	 * @throws \OverflowException if the key would overflow - rebalance needed
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
	 * deleted, wrong stack, the moved card itself) is invalid input - the
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
	 * (2026-07-22T12:00:00.000Z) - the latter is what JS Date.toISOString()
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
		// becomes March 2nd) and only records it in getLastErrors - reject
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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Flat checklist (todo) items on a card. Mirrors the assignee/label flow:
 * reading needs READ on the card's board, every mutation needs EDIT and appends
 * a card-targeted row to the `kanso_changes` log (so the board ETag bumps and
 * realtime clients refetch the card and its progress count). Items are ordered
 * by a fractional sort key, so a reorder is a single-row UPDATE - a sort-key
 * overflow surfaces as 409 rebalance_required via the controller trait, exactly
 * like a card move.
 *
 * An item can be a rich "step" (#3745): assigned to ONE user (with the
 * assignee's board side frozen into `assigned_role` at assign time), carrying
 * its own due date, and stamping `done_at` when done flips. Assignment
 * mirrors the card-assignee rules: the assignee must hold READ on the board
 * AND be able to SEE the card - a step of a hidden card in someone's
 * "my steps" would be an existence oracle.
 */
class ChecklistService {
	private const MAX_TITLE_LENGTH = 255;

	public function __construct(
		private ChecklistItemMapper $itemMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private SortKeyService $sortKeyService,
		private IDBConnection $db,
		private CardVisibilityGuard $visibilityGuard,
		private BoardAccess $boardAccess,
		private NotificationService $notificationService,
	) {
	}

	/**
	 * All items of the card, in display order. Requires READ on the board.
	 *
	 * @return ChecklistItem[]
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function listItems(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		return $this->itemMapper->findByCard($cardId);
	}

	/**
	 * Appends an item to the card's checklist. Requires EDIT. `$done` seeds the
	 * item's checked state in the same insert (used when cloning a card's
	 * checklist so a done item is a single write, not an add-then-toggle), and
	 * `$dueDate` seeds the step due date the same way (the clone paths KEEP due
	 * dates while dropping assignee + done_at, #3745).
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the title is empty or too long
	 * @throws \OverflowException if the appended sort key would overflow (rebalance needed)
	 */
	public function addItem(int $cardId, string $title, string $actorUid, bool $done = false, ?\DateTime $dueDate = null): ChecklistItem {
		$title = $this->normalizeTitle($title);
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		$last = $this->itemMapper->findLastByCard($cardId);
		$sortKey = $last === null
			? $this->sortKeyService->initial()
			: $this->sortKeyService->after($last->getSortKey());

		$item = new ChecklistItem();
		$item->setCardId($cardId);
		$item->setTitle($title);
		$item->setDone($done);
		$item->setSortKey($sortKey);
		$item->setCreatedAt(time());
		if ($dueDate !== null) {
			$item->setDueDate($dueDate);
		}

		// Atomic item-write + card change-row (#3579); push after commit.
		return $this->writeItemChange($card, $actorUid, fn (): ChecklistItem => $this->itemMapper->insert($item));
	}

	/**
	 * Renames and/or toggles an item. Both fields are optional; a no-op write
	 * (nothing changed) still returns the item but writes no change row.
	 * Requires EDIT.
	 *
	 * @throws DoesNotExistException if the item, card or board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if a supplied title is empty or too long
	 */
	public function updateItem(int $itemId, ?string $title, ?bool $done, string $actorUid): ChecklistItem {
		$item = $this->itemMapper->find($itemId);
		$card = $this->loadCard($item->getCardId());
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		$changed = false;
		if ($title !== null) {
			$normalized = $this->normalizeTitle($title);
			if ($normalized !== $item->getTitle()) {
				$item->setTitle($normalized);
				$changed = true;
			}
		}
		if ($done !== null && $done !== $item->getDone()) {
			$item->setDone($done);
			// `done` stays the source of truth; `done_at` is only the stamp of
			// the flip (#3745) - set on complete, cleared on un-done.
			$item->setDoneAt($done ? time() : null);
			$changed = true;
		}

		if (!$changed) {
			return $item;
		}

		// Atomic item-write + card change-row (#3579); push after commit.
		return $this->writeItemChange($card, $actorUid, fn (): ChecklistItem => $this->itemMapper->update($item));
	}

	/**
	 * Moves an item directly after $afterItemId (null = to the top of the
	 * checklist). Requires EDIT. A no-op (already in place) writes no change
	 * row.
	 *
	 * @throws DoesNotExistException if the item, card or board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if $afterItemId is not an item of the same card
	 * @throws \OverflowException if the new sort key would overflow (rebalance needed)
	 */
	public function moveItem(int $itemId, ?int $afterItemId, string $actorUid): ChecklistItem {
		if ($afterItemId === $itemId) {
			throw new InvalidInputException('An item cannot be moved after itself');
		}

		$item = $this->itemMapper->find($itemId);
		$card = $this->loadCard($item->getCardId());
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		// Siblings in display order, excluding the item being moved.
		$siblings = array_values(array_filter(
			$this->itemMapper->findByCard($item->getCardId()),
			static fn (ChecklistItem $i): bool => $i->getId() !== $itemId
		));

		if ($afterItemId === null) {
			$newKey = $siblings === []
				? $this->sortKeyService->initial()
				: $this->sortKeyService->before($siblings[0]->getSortKey());
		} else {
			$anchorIndex = null;
			foreach ($siblings as $idx => $sibling) {
				if ($sibling->getId() === $afterItemId) {
					$anchorIndex = $idx;
					break;
				}
			}
			if ($anchorIndex === null) {
				throw new InvalidInputException('afterItemId is not an item of this card');
			}
			$anchor = $siblings[$anchorIndex];
			$next = $siblings[$anchorIndex + 1] ?? null;
			$newKey = $next === null
				? $this->sortKeyService->after($anchor->getSortKey())
				: $this->sortKeyService->between($anchor->getSortKey(), $next->getSortKey());
		}

		if ($newKey === $item->getSortKey()) {
			return $item;
		}

		$item->setSortKey($newKey);

		// Atomic item-write + card change-row (#3579); push after commit.
		return $this->writeItemChange($card, $actorUid, fn (): ChecklistItem => $this->itemMapper->update($item));
	}

	/**
	 * Removes an item. Requires EDIT.
	 *
	 * @throws DoesNotExistException if the item, card or board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function deleteItem(int $itemId, string $actorUid): void {
		$item = $this->itemMapper->find($itemId);
		$card = $this->loadCard($item->getCardId());
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		// Atomic item-delete + card change-row (#3579); push after commit. The
		// deleted item is returned so the shared helper's contract holds; the
		// caller (void) ignores it.
		$this->writeItemChange($card, $actorUid, function () use ($item): ChecklistItem {
			$this->itemMapper->delete($item);
			return $item;
		});
	}

	/**
	 * Assigns the step to ONE user (steps are user-only, no groups - #3745)
	 * and FREEZES the assignee's board side into `assigned_role` at this
	 * moment, resolved by {@see BoardAccess::contextFor()}: the derived
	 * wait-state (epic 6) must stay stable even if the assignee later flips
	 * role or leaves the board. Requires EDIT for the actor; the assignee must
	 * hold READ on the board AND be able to SEE the card (a step of a card
	 * hidden from its own assignee would surface the card in their "my steps"
	 * - an existence oracle). Re-assigning the same user is a no-op;
	 * re-assigning a different user replaces them (single-assignee model) and
	 * dismisses the previous assignee's bell notification.
	 *
	 * @throws DoesNotExistException if the item, card or board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the assignee cannot read the board, cannot see the card, or does not exist
	 */
	public function assignItem(int $itemId, string $participantUid, string $actorUid): ChecklistItem {
		$item = $this->itemMapper->find($itemId);
		$card = $this->loadCard($item->getCardId());
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		// Directly or via a group ACL, the assignee must at least see the
		// board. Unknown users hold no permissions, so they fail this too
		// (mirrors AssigneeService::assign()).
		if (($this->permissionService->getPermissions($board, $participantUid) & PermissionService::PERMISSION_READ) === 0) {
			throw new InvalidInputException('User has no access to this board');
		}
		// ...and the CARD itself: assigning a step of a card its assignee
		// cannot see would leak the card into their my-steps feed.
		if (!$this->visibilityGuard->isVisible($board, $card, $participantUid)) {
			throw new InvalidInputException('User cannot see this card');
		}

		if ($item->getAssignedUser() === $participantUid) {
			return $item;
		}
		$previousAssignee = $item->getAssignedUser();

		try {
			$role = $this->boardAccess->contextFor($board, $participantUid)->role;
		} catch (NotAMemberException) {
			// READ resolved but no membership row - cannot happen through the
			// permission model above, but never freeze a made-up role.
			throw new InvalidInputException('User has no access to this board');
		}

		$item->setAssignedUser($participantUid);
		$item->setAssignedRole($role);
		$item->setAssignedAt(time());

		// Atomic item-write + card change-row (#3579); push after commit.
		$saved = $this->writeItemChange($card, $actorUid, fn (): ChecklistItem => $this->itemMapper->update($item));

		// Bell notifications only after the commit stuck. The audience gate is
		// the assign validation itself (the assignee provably sees the card);
		// self-assignment is suppressed inside the service.
		if ($previousAssignee !== null) {
			$this->notificationService->dismissStepAssigned($itemId, $previousAssignee);
		}
		$this->notificationService->notifyStepAssigned($itemId, $card->getId(), $participantUid, $actorUid);

		return $saved;
	}

	/**
	 * Clears the step's assignee (and the frozen role + assigned_at with it).
	 * Idempotent: unassigning an unassigned step is a no-op and writes no
	 * change row. Requires EDIT.
	 *
	 * @throws DoesNotExistException if the item, card or board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function unassignItem(int $itemId, string $actorUid): ChecklistItem {
		$item = $this->itemMapper->find($itemId);
		$card = $this->loadCard($item->getCardId());
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		$previousAssignee = $item->getAssignedUser();
		if ($previousAssignee === null) {
			return $item;
		}

		$item->setAssignedUser(null);
		$item->setAssignedRole(null);
		$item->setAssignedAt(null);

		// Atomic item-write + card change-row (#3579); push after commit.
		$saved = $this->writeItemChange($card, $actorUid, fn (): ChecklistItem => $this->itemMapper->update($item));

		// Clear any pending "step assigned to you" bell for this step.
		$this->notificationService->dismissStepAssigned($itemId, $previousAssignee);

		return $saved;
	}

	/**
	 * Sets or clears the step's own due date. $due uses the same wire format
	 * as the card due date ({@see DueDateParser}): strict ISO 8601, '' or null
	 * clears. Requires EDIT. A no-op (same instant) writes no change row.
	 *
	 * @throws DoesNotExistException if the item, card or board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if $due is not a valid ISO 8601 datetime
	 */
	public function setItemDue(int $itemId, ?string $due, string $actorUid): ChecklistItem {
		$item = $this->itemMapper->find($itemId);
		$card = $this->loadCard($item->getCardId());
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		$parsed = DueDateParser::parse($due ?? '');
		if ($parsed?->getTimestamp() === $item->getDueDate()?->getTimestamp()) {
			return $item;
		}
		$item->setDueDate($parsed);

		// Atomic item-write + card change-row (#3579); push after commit.
		return $this->writeItemChange($card, $actorUid, fn (): ChecklistItem => $this->itemMapper->update($item));
	}

	/**
	 * Runs a checklist-item write and the card's `kanso_changes` row atomically
	 * (#3579): a checklist change is a card UPDATE as far as sync is concerned
	 * (the board ETag bumps and clients refetch the card's items + the board's
	 * progress count). The item write in $write and the card change-row insert
	 * commit together, or roll back together on any failure; the realtime push is
	 * emitted only AFTER commit.
	 *
	 * @param callable():ChecklistItem $write performs the item write and returns the row
	 * @throws \Throwable rethrows whatever the write or the change-row insert throws
	 */
	private function writeItemChange(Card $card, string $actorUid, callable $write): ChecklistItem {
		$this->db->beginTransaction();
		try {
			$saved = $write();
			$this->changeNotifier->recordChange(
				$card->getBoardId(),
				Change::ENTITY_CARD,
				$card->getId(),
				Change::ACTION_UPDATE,
				$actorUid,
				Change::VERB_CHECKLIST,
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast.
		$this->changeNotifier->pushBoardChanged($card->getBoardId());

		return $saved;
	}

	/**
	 * @throws InvalidInputException if the title is empty or too long
	 */
	private function normalizeTitle(string $title): string {
		$title = trim($title);
		if ($title === '') {
			throw new InvalidInputException('Checklist item title must not be empty');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new InvalidInputException('Checklist item title is too long');
		}
		return $title;
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

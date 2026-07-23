<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Flat checklist (todo) items on a card. Mirrors the assignee/label flow:
 * reading needs READ on the card's board, every mutation needs EDIT and appends
 * a card-targeted row to the `kanso_changes` log (so the board ETag bumps and
 * realtime clients refetch the card and its progress count). Items are ordered
 * by a fractional sort key, so a reorder is a single-row UPDATE — a sort-key
 * overflow surfaces as 409 rebalance_required via the controller trait, exactly
 * like a card move.
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

		return $this->itemMapper->findByCard($cardId);
	}

	/**
	 * Appends an item to the card's checklist. Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the title is empty or too long
	 * @throws \OverflowException if the appended sort key would overflow (rebalance needed)
	 */
	public function addItem(int $cardId, string $title, string $actorUid): ChecklistItem {
		$title = $this->normalizeTitle($title);
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		$last = $this->itemMapper->findLastByCard($cardId);
		$sortKey = $last === null
			? $this->sortKeyService->initial()
			: $this->sortKeyService->after($last->getSortKey());

		$item = new ChecklistItem();
		$item->setCardId($cardId);
		$item->setTitle($title);
		$item->setDone(false);
		$item->setSortKey($sortKey);
		$item->setCreatedAt(time());
		$saved = $this->itemMapper->insert($item);

		$this->notifyCard($card, $actorUid);

		return $saved;
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
			$changed = true;
		}

		if (!$changed) {
			return $item;
		}

		$saved = $this->itemMapper->update($item);
		$this->notifyCard($card, $actorUid);

		return $saved;
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
		$saved = $this->itemMapper->update($item);
		$this->notifyCard($card, $actorUid);

		return $saved;
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

		$this->itemMapper->delete($item);
		$this->notifyCard($card, $actorUid);
	}

	/**
	 * A checklist change is a card update as far as sync is concerned: the
	 * board ETag bumps and clients refetch the card (items) and the board
	 * (progress count).
	 */
	private function notifyCard(Card $card, string $actorUid): void {
		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$actorUid
		);
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

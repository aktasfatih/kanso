<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The trash: listing, restoring and permanently deleting soft-deleted cards.
 * A card delete (CardService::delete) is a soft-delete (deleted_at) and the
 * board payload hides such cards; this service is the only way to see, recover
 * or truly remove them.
 *
 *   - listTrash needs READ on the board;
 *   - restore needs EDIT (it puts a card back into play);
 *   - purge needs MANAGE — it is the one irreversible, hard delete in the app,
 *     cascading to the card's labels, assignees, checklist items and comments.
 *
 * Restore/purge only ever act on an ALREADY-trashed card (deleted_at > 0); a
 * live card is rejected as invalid input. Both append a card-targeted row to
 * `kanso_changes` so the board ETag bumps and clients refetch.
 */
class TrashService {
	public function __construct(
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CommentMapper $commentMapper,
	) {
	}

	/**
	 * Soft-deleted cards of the board (summaries), most-recently-deleted first.
	 * Requires READ.
	 *
	 * @return Card[]
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function listTrash(int $boardId, string $actorUid): array {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		return $this->cardMapper->findDeletedByBoard($boardId);
	}

	/**
	 * Restores a trashed card (clears deleted_at) so it returns to its stack.
	 * Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist, or the board is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the card is not in the trash
	 */
	public function restore(int $cardId, string $actorUid): Card {
		$card = $this->loadTrashedCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		$card->setDeletedAt(0);
		$card->setLastModified(time());
		$card = $this->cardMapper->update($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_CREATE,
			$actorUid
		);

		return $card;
	}

	/**
	 * Permanently deletes a trashed card and everything hanging off it (labels,
	 * assignees, checklist items, comments). Requires MANAGE. Irreversible.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist, or the board is deleted
	 * @throws NotPermittedException if the actor may not manage the board
	 * @throws InvalidInputException if the card is not in the trash
	 */
	public function purge(int $cardId, string $actorUid): void {
		$card = $this->loadTrashedCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_MANAGE);

		$this->cardLabelMapper->deleteByCard($cardId);
		$this->cardAssigneeMapper->deleteByCard($cardId);
		$this->checklistItemMapper->deleteByCard($cardId);
		$this->commentMapper->deleteByCard($cardId);
		$this->cardMapper->delete($card);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_DELETE,
			$actorUid
		);
	}

	/**
	 * @throws DoesNotExistException if the card does not exist
	 * @throws InvalidInputException if the card is not in the trash (deleted_at == 0)
	 */
	private function loadTrashedCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() === 0) {
			throw new InvalidInputException('Card ' . $id . ' is not in the trash');
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

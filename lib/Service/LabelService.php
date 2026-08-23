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
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Label CRUD and card assignments. Every mutation appends a row to the
 * `kanso_changes` log in the same flow (see BoardService). Label CRUD is a
 * board-management concern (MANAGE, like editing the board itself); assigning
 * or unassigning a label on a card only needs EDIT - for delta sync those are
 * card updates, so their change rows target the card.
 */
class LabelService {
	private const MAX_TITLE_LENGTH = 100;

	// Cap each stored detail string, consistent with CardService's description cap.
	private const MAX_DETAIL_LENGTH = 10000;

	public function __construct(
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private IDBConnection $db,
		private CardVisibilityGuard $visibilityGuard,
		private ChangeDetailMapper $changeDetailMapper,
	) {
	}

	/**
	 * Creates a label on the board.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid title or color
	 */
	public function create(int $boardId, string $title, ?string $color, string $uid): Label {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$label = new Label();
		$label->setBoardId($boardId);
		$label->setTitle($this->validateTitle($title));
		$label->setColor(ColorValidator::assertValid($color));
		$label = $this->labelMapper->insert($label);

		$this->changeNotifier->notify(
			$boardId,
			Change::ENTITY_LABEL,
			$label->getId(),
			Change::ACTION_CREATE,
			$uid
		);

		return $label;
	}

	/**
	 * Updates the given fields (null = leave unchanged; an empty color
	 * string clears the color).
	 *
	 * @throws DoesNotExistException if the label or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid title or color
	 */
	public function update(int $id, ?string $title, ?string $color, string $uid): Label {
		$label = $this->labelMapper->find($id);
		$board = $this->loadBoard($label->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		if ($title !== null) {
			$label->setTitle($this->validateTitle($title));
		}
		if ($color !== null) {
			$label->setColor(ColorValidator::assertValid($color));
		}

		$label = $this->labelMapper->update($label);

		$this->changeNotifier->notify(
			$label->getBoardId(),
			Change::ENTITY_LABEL,
			$id,
			Change::ACTION_UPDATE,
			$uid
		);

		return $label;
	}

	/**
	 * Hard-deletes the label and all of its card assignments (labels have no
	 * soft-delete - a deleted label simply disappears from every card).
	 *
	 * @throws DoesNotExistException if the label or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function delete(int $id, string $uid): void {
		$label = $this->labelMapper->find($id);
		$board = $this->loadBoard($label->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		// Cascade + label row + change row are one transaction (same convention
		// as CardService::move): a torn delete would leave orphaned assignment
		// rows reappearing in labelIds, or a deletion invisible to the ETag.
		$this->db->beginTransaction();
		try {
			$this->cardLabelMapper->deleteByLabel($id);
			$this->labelMapper->delete($label);
			$this->changeNotifier->notify(
				$label->getBoardId(),
				Change::ENTITY_LABEL,
				$id,
				Change::ACTION_DELETE,
				$uid
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Assigns the label to the card. Idempotent: re-assigning an already
	 * assigned label is a no-op and writes no change row.
	 *
	 * @throws DoesNotExistException if the card, the label or the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException if the label belongs to another board
	 */
	public function assign(int $cardId, int $labelId, string $uid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		$label = $this->labelMapper->find($labelId);
		if ($label->getBoardId() !== $card->getBoardId()) {
			throw new InvalidInputException('Cannot assign a label from another board');
		}

		if ($this->cardLabelMapper->exists($cardId, $labelId)) {
			return;
		}

		$this->cardLabelMapper->insertAssignment($cardId, $labelId);

		$change = $this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$uid,
			verb: Change::VERB_LABELED,
		);
		// Record the label title so the Activity feed can say WHICH label was added.
		$this->changeDetailMapper->insertDetail($change->getId(), null, $this->capDetail($label->getTitle()));
	}

	/**
	 * Removes the label from the card. Idempotent: unassigning an absent
	 * assignment is a no-op and writes no change row.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 */
	public function unassign(int $cardId, int $labelId, string $uid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $uid);

		// Resolve the label title BEFORE the assignment is gone, so the Activity feed
		// can say WHICH label was removed. A label deleted out from under a stale
		// assignment leaves no title - the detail is simply skipped then.
		$labelTitle = null;
		try {
			$labelTitle = $this->labelMapper->find($labelId)->getTitle();
		} catch (DoesNotExistException) {
			// Label already gone; record the change without a from/to detail.
		}

		if ($this->cardLabelMapper->deleteAssignment($cardId, $labelId) === 0) {
			return;
		}

		$change = $this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$uid,
			verb: Change::VERB_UNLABELED,
		);
		if ($labelTitle !== null) {
			$this->changeDetailMapper->insertDetail($change->getId(), $this->capDetail($labelTitle), null);
		}
	}

	/**
	 * Caps a detail string to {@see self::MAX_DETAIL_LENGTH} chars (multibyte-safe),
	 * consistent with the description cap in CardService.
	 */
	private function capDetail(string $value): string {
		return mb_substr($value, 0, self::MAX_DETAIL_LENGTH);
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
}

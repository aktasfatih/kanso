<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ReviewType;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Review-type CRUD - per-board, customizable review categories (QA / Code /
 * Legal / …). Mirrors {@see LabelService}: managing the type list is a
 * board-management concern (MANAGE), and every mutation appends a
 * `kanso_changes` row (ENTITY_REVIEW_TYPE) so the board ETag bumps and clients
 * refetch the type list. Deleting a type clears it from any review that used
 * it - the review survives, untyped.
 */
class ReviewTypeService {
	private const MAX_TITLE_LENGTH = 100;

	public function __construct(
		private ReviewTypeMapper $reviewTypeMapper,
		private CardReviewMapper $cardReviewMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private IDBConnection $db,
	) {
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid title or color
	 */
	public function create(int $boardId, string $title, ?string $color, string $uid): ReviewType {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$type = new ReviewType();
		$type->setBoardId($boardId);
		$type->setTitle($this->validateTitle($title));
		$type->setColor(ColorValidator::assertValid($color));
		$type = $this->reviewTypeMapper->insert($type);

		$this->changeNotifier->notify(
			$boardId,
			Change::ENTITY_REVIEW_TYPE,
			$type->getId(),
			Change::ACTION_CREATE,
			$uid
		);

		return $type;
	}

	/**
	 * @throws DoesNotExistException if the type or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid title or color
	 */
	public function update(int $id, ?string $title, ?string $color, string $uid): ReviewType {
		$type = $this->reviewTypeMapper->find($id);
		$board = $this->loadBoard($type->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		if ($title !== null) {
			$type->setTitle($this->validateTitle($title));
		}
		if ($color !== null) {
			$type->setColor(ColorValidator::assertValid($color));
		}

		$type = $this->reviewTypeMapper->update($type);

		$this->changeNotifier->notify(
			$type->getBoardId(),
			Change::ENTITY_REVIEW_TYPE,
			$id,
			Change::ACTION_UPDATE,
			$uid
		);

		return $type;
	}

	/**
	 * Deletes the review type and clears it from every review that used it (the
	 * reviews survive, untyped). The clear + delete + change row are one
	 * transaction (same convention as LabelService::delete).
	 *
	 * @throws DoesNotExistException if the type or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function delete(int $id, string $uid): void {
		$type = $this->reviewTypeMapper->find($id);
		$board = $this->loadBoard($type->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$this->db->beginTransaction();
		try {
			$this->cardReviewMapper->clearType($id);
			$this->reviewTypeMapper->delete($type);
			$this->changeNotifier->notify(
				$type->getBoardId(),
				Change::ENTITY_REVIEW_TYPE,
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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Board CRUD. Every mutation appends a row to the `kanso_changes` log in
 * the same flow, so a board's max change id always reflects its latest
 * state (that id is the board ETag and the delta-sync cursor).
 */
class BoardService {
	private const MAX_TITLE_LENGTH = 100;

	public function __construct(
		private BoardMapper $boardMapper,
		private ChangeMapper $changeMapper,
		private PermissionService $permissionService,
	) {
	}

	/**
	 * All boards visible to the user (owned or shared with them).
	 *
	 * @return Board[]
	 */
	public function findAll(string $uid): array {
		return $this->boardMapper->findAllForUser(
			$uid,
			$this->permissionService->getUserGroupIds($uid)
		);
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not read the board
	 */
	public function find(int $id, string $uid): Board {
		$board = $this->loadBoard($id);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_READ);
		return $board;
	}

	/**
	 * Creates a board owned by $uid and writes the initial ACTION_CREATE
	 * change row, so the board's max change id is never null.
	 *
	 * @throws InvalidInputException on invalid title or color
	 */
	public function create(string $title, ?string $color, string $uid): Board {
		$now = time();

		$board = new Board();
		$board->setTitle($this->validateTitle($title));
		$board->setColor(ColorValidator::assertValid($color));
		$board->setOwner($uid);
		$board->setArchived(false);
		$board->setLastModified($now);
		$board->setDeletedAt(0);
		$board = $this->boardMapper->insert($board);

		$this->changeMapper->insertChange(
			$board->getId(),
			Change::ENTITY_BOARD,
			$board->getId(),
			Change::ACTION_CREATE,
			$uid,
			$now
		);

		return $board;
	}

	/**
	 * Updates the given fields (null = leave unchanged; an empty color
	 * string clears the color).
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 * @throws InvalidInputException on invalid title or color
	 */
	public function update(int $id, ?string $title, ?string $color, ?bool $archived, string $uid): Board {
		$board = $this->loadBoard($id);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		if ($title !== null) {
			$board->setTitle($this->validateTitle($title));
		}
		if ($color !== null) {
			$board->setColor(ColorValidator::assertValid($color));
		}
		if ($archived !== null) {
			$board->setArchived($archived);
		}

		$now = time();
		$board->setLastModified($now);
		$board = $this->boardMapper->update($board);

		$this->changeMapper->insertChange(
			$id,
			Change::ENTITY_BOARD,
			$id,
			Change::ACTION_UPDATE,
			$uid,
			$now
		);

		return $board;
	}

	/**
	 * Soft-deletes the board (sets deleted_at).
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not manage the board
	 */
	public function delete(int $id, string $uid): Board {
		$board = $this->loadBoard($id);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_MANAGE);

		$now = time();
		$board->setDeletedAt($now);
		$board->setLastModified($now);
		$board = $this->boardMapper->update($board);

		$this->changeMapper->insertChange(
			$id,
			Change::ENTITY_BOARD,
			$id,
			Change::ACTION_DELETE,
			$uid,
			$now
		);

		return $board;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $id): Board {
		$board = $this->boardMapper->find($id);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $id . ' is deleted');
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

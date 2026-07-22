<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Stack CRUD. Every mutation appends a row to the `kanso_changes` log in
 * the same flow (see BoardService). New stacks are appended to the end of
 * the board via fractional sort keys — no sibling renumbering.
 */
class StackService {
	private const MAX_TITLE_LENGTH = 100;

	public function __construct(
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private ChangeMapper $changeMapper,
		private PermissionService $permissionService,
		private SortKeyService $sortKeyService,
	) {
	}

	/**
	 * Creates a stack at the end of the board.
	 *
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title
	 */
	public function create(int $boardId, string $title, string $uid): Stack {
		$board = $this->loadBoard($boardId);
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$stacks = $this->stackMapper->findByBoard($boardId);
		$lastStack = end($stacks);
		$sortKey = $lastStack === false
			? $this->sortKeyService->initial()
			: $this->sortKeyService->after($lastStack->getSortKey());

		$now = time();

		$stack = new Stack();
		$stack->setBoardId($boardId);
		$stack->setTitle($this->validateTitle($title));
		$stack->setSortKey($sortKey);
		$stack->setArchived(false);
		$stack->setDeletedAt(0);
		$stack = $this->stackMapper->insert($stack);

		$this->changeMapper->insertChange(
			$boardId,
			Change::ENTITY_STACK,
			$stack->getId(),
			Change::ACTION_CREATE,
			$uid,
			$now
		);

		return $stack;
	}

	/**
	 * Updates the given fields (null = leave unchanged).
	 *
	 * @throws DoesNotExistException if the stack or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title
	 */
	public function update(int $id, ?string $title, ?bool $archived, string $uid): Stack {
		$stack = $this->loadStack($id);
		$board = $this->loadBoard($stack->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		if ($title !== null) {
			$stack->setTitle($this->validateTitle($title));
		}
		if ($archived !== null) {
			$stack->setArchived($archived);
		}

		$stack = $this->stackMapper->update($stack);

		$this->changeMapper->insertChange(
			$stack->getBoardId(),
			Change::ENTITY_STACK,
			$id,
			Change::ACTION_UPDATE,
			$uid,
			time()
		);

		return $stack;
	}

	/**
	 * Soft-deletes the stack (sets deleted_at).
	 *
	 * @throws DoesNotExistException if the stack or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 */
	public function delete(int $id, string $uid): Stack {
		$stack = $this->loadStack($id);
		$board = $this->loadBoard($stack->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$now = time();
		$stack->setDeletedAt($now);
		$stack = $this->stackMapper->update($stack);

		$this->changeMapper->insertChange(
			$stack->getBoardId(),
			Change::ENTITY_STACK,
			$id,
			Change::ACTION_DELETE,
			$uid,
			$now
		);

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

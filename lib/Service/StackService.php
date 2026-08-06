<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Stack CRUD. Every mutation appends a row to the `kanso_changes` log in
 * the same flow (see BoardService). New stacks are appended to the end of
 * the board via fractional sort keys - no sibling renumbering.
 */
class StackService {
	private const MAX_TITLE_LENGTH = 100;

	public function __construct(
		private StackMapper $stackMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private SortKeyService $sortKeyService,
		private IDBConnection $db,
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

		$stack = new Stack();
		$stack->setBoardId($boardId);
		$stack->setTitle($this->validateTitle($title));
		$stack->setSortKey($sortKey);
		$stack->setArchived(false);
		$stack->setDeletedAt(0);

		// Atomic entity-write + change-row (#3579); push after commit.
		return $this->writeStackChange(
			$boardId,
			Change::ACTION_CREATE,
			$uid,
			fn (): Stack => $this->stackMapper->insert($stack),
		);
	}

	/**
	 * Updates the given fields (null = leave unchanged). `role` is one of the
	 * Stack::ROLE_* constants (moving a card into a done-role stack stamps its
	 * done_at - see CardService::move); `wipLimit` is a non-negative soft cap
	 * (null clears it - no server-side enforcement, the client warns).
	 *
	 * @throws DoesNotExistException if the stack or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException on invalid title, role or wip limit
	 */
	public function update(
		int $id,
		?string $title,
		?bool $archived,
		?int $role,
		?int $wipLimit,
		string $uid,
		?string $color = null,
	): Stack {
		$stack = $this->loadStack($id);
		$board = $this->loadBoard($stack->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		if ($title !== null) {
			$stack->setTitle($this->validateTitle($title));
		}
		if ($archived !== null) {
			$stack->setArchived($archived);
		}
		if ($role !== null) {
			$stack->setRole($this->validateRole($role));
		}
		if ($wipLimit !== null) {
			$stack->setWipLimit($this->validateWipLimit($wipLimit));
		}
		if ($color !== null) {
			// '' clears; otherwise the shared validator normalises to bare hex.
			$stack->setColor($color === '' ? null : ColorValidator::assertValid($color));
		}

		// Atomic entity-write + change-row (#3579); push after commit.
		return $this->writeStackChange(
			$stack->getBoardId(),
			Change::ACTION_UPDATE,
			$uid,
			fn (): Stack => $this->stackMapper->update($stack),
		);
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

		// Atomic entity-write + change-row (#3579); push after commit.
		return $this->writeStackChange(
			$stack->getBoardId(),
			Change::ACTION_DELETE,
			$uid,
			fn (): Stack => $this->stackMapper->update($stack),
		);
	}

	/**
	 * Restores a soft-deleted stack (clears deleted_at) - the undo for
	 * {@see self::delete()}. The stack's cards were never touched by the delete,
	 * so they reappear with it. Emits an ACTION_CREATE change so clients re-add
	 * the column. Rejects a live stack so a stale undo can't resurrect one that
	 * was meanwhile recreated.
	 *
	 * @throws DoesNotExistException if the stack does not exist, is not deleted, or its board is gone
	 * @throws NotPermittedException if the user may not edit the board
	 */
	public function restore(int $id, string $uid): Stack {
		$stack = $this->stackMapper->find($id);
		if ($stack->getDeletedAt() === 0) {
			throw new DoesNotExistException('Stack ' . $id . ' is not deleted');
		}
		$board = $this->loadBoard($stack->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		$stack->setDeletedAt(0);

		// Atomic entity-write + change-row (#3579); push after commit. Emits an
		// ACTION_CREATE so clients re-add the restored column.
		return $this->writeStackChange(
			$stack->getBoardId(),
			Change::ACTION_CREATE,
			$uid,
			fn (): Stack => $this->stackMapper->update($stack),
		);
	}

	/**
	 * Moves the stack inside its board: directly after $afterStackId, or to
	 * the front of the board when $afterStackId is null. Neighbours are
	 * resolved in-memory from the board's stack list with the moved stack
	 * excluded - otherwise dropping on the left edge of the stack to the
	 * right would yield the moved stack as its own predecessor. The
	 * transaction makes the stack update and its change row atomic (rollback
	 * on failure), mirroring CardService::move - including its accepted
	 * concurrent-move caveat (duplicate keys possible under READ COMMITTED;
	 * cosmetic, next move repairs it).
	 *
	 * @throws DoesNotExistException if the stack or its board does not exist or is deleted
	 * @throws NotPermittedException if the user may not edit the board
	 * @throws InvalidInputException if $afterStackId is unusable (missing, deleted, other board, the moved stack itself)
	 * @throws \OverflowException if the new sort key would overflow (board needs a rebalance)
	 */
	public function move(int $id, ?int $afterStackId, string $uid): Stack {
		$stack = $this->loadStack($id);
		$board = $this->loadBoard($stack->getBoardId());
		$this->permissionService->assertPermission($board, $uid, PermissionService::PERMISSION_EDIT);

		// Board stacks in display order, without the moved stack itself.
		$siblings = array_values(array_filter(
			$this->stackMapper->findByBoard($stack->getBoardId()),
			static fn (Stack $s): bool => $s->getId() !== $id
		));
		$anchor = $afterStackId === null ? null : $this->resolveAnchor($afterStackId, $id, $siblings);

		// Change row is written inside the transaction (delta-sync source of
		// truth); the realtime push is deferred until after commit.
		$this->db->beginTransaction();
		try {
			$stack->setSortKey($this->deriveMoveKey($siblings, $anchor));
			$stack = $this->stackMapper->update($stack);

			$this->changeNotifier->recordChange(
				$stack->getBoardId(),
				Change::ENTITY_STACK,
				$id,
				Change::ACTION_MOVE,
				$uid,
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast the move.
		$this->changeNotifier->pushBoardChanged($stack->getBoardId());

		return $stack;
	}

	/**
	 * Runs a single stack entity write and its `kanso_changes` row atomically
	 * (#3579): the mapper write in $write and the change-row insert commit
	 * together, or roll back together on any failure - so no stack mutation is
	 * visible without its delta-sync row. The realtime push is emitted only AFTER
	 * commit. Mirrors {@see self::move()}'s pattern for the non-move stack
	 * mutators (create/update/delete/restore).
	 *
	 * @param callable():Stack $write performs the entity write and returns the row
	 * @throws \Throwable rethrows whatever the write or the change-row insert throws
	 */
	private function writeStackChange(int $boardId, int $action, ?string $uid, callable $write): Stack {
		$this->db->beginTransaction();
		try {
			$stack = $write();
			$this->changeNotifier->recordChange(
				$boardId,
				Change::ENTITY_STACK,
				$stack->getId(),
				$action,
				$uid,
			);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Commit succeeded - now it is safe to broadcast.
		$this->changeNotifier->pushBoardChanged($boardId);

		return $stack;
	}

	/**
	 * New sort key for the moved stack landing after $anchor (null = front of
	 * the board). $siblings is the board's stack order with the moved stack
	 * excluded.
	 *
	 * @param list<Stack> $siblings
	 * @throws \OverflowException if the key would overflow - rebalance needed
	 */
	private function deriveMoveKey(array $siblings, ?Stack $anchor): string {
		if ($anchor === null) {
			$first = $siblings[0] ?? null;
			return $first === null
				? $this->sortKeyService->initial()
				: $this->sortKeyService->before($first->getSortKey());
		}
		$next = null;
		foreach ($siblings as $index => $sibling) {
			if ($sibling->getId() === $anchor->getId()) {
				$next = $siblings[$index + 1] ?? null;
				break;
			}
		}
		return $next === null
			? $this->sortKeyService->after($anchor->getSortKey())
			: $this->sortKeyService->between($anchor->getSortKey(), $next->getSortKey());
	}

	/**
	 * Validates the move anchor against the in-memory sibling list. Any
	 * unusable anchor (the moved stack itself, or not among the board's
	 * live stacks) is invalid input - the client's picture of the board is
	 * stale, not the moved stack's fault.
	 *
	 * @param list<Stack> $siblings
	 * @throws InvalidInputException
	 */
	private function resolveAnchor(int $afterStackId, int $movedStackId, array $siblings): Stack {
		if ($afterStackId === $movedStackId) {
			throw new InvalidInputException('afterStackId must not be the moved stack itself');
		}
		foreach ($siblings as $sibling) {
			if ($sibling->getId() === $afterStackId) {
				return $sibling;
			}
		}
		throw new InvalidInputException('Stack ' . $afterStackId . ' is not on the board');
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
	 * @throws InvalidInputException if the role is not one of the Stack::ROLE_* values
	 */
	private function validateRole(int $role): int {
		if ($role < Stack::ROLE_NONE || $role > Stack::ROLE_DONE) {
			throw new InvalidInputException(
				'Role must be between ' . Stack::ROLE_NONE . ' and ' . Stack::ROLE_DONE
			);
		}
		return $role;
	}

	/**
	 * @throws InvalidInputException if the wip limit is negative
	 */
	private function validateWipLimit(int $wipLimit): int {
		if ($wipLimit < 0) {
			throw new InvalidInputException('WIP limit must not be negative');
		}
		return $wipLimit;
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

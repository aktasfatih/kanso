<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\BoardGroup;
use OCA\Kanso\Db\BoardGroupMapper;
use OCA\Kanso\Db\BoardGroupMember;
use OCA\Kanso\Db\BoardGroupMemberMapper;

/**
 * Per-user board folders for the nav (#3529).
 *
 * FLAT, one-level, PER-USER organization: my folders are invisible to you and
 * are never shared - so, exactly like {@see SubscriptionService} board watches,
 * this is personal state in its own tables, NOT a column on the shared board
 * row and NOT a board `kanso_changes` entry (it must not churn the board ETag
 * for everyone). This is nav organization ONLY; it is distinct from Projects
 * (cross-board CARD collections).
 *
 * Every operation is scoped to the caller: folders are looked up by uid (a
 * crafted folder id belonging to another user resolves to nothing), and a board
 * can only be filed into a folder the caller can READ (delegated to
 * {@see BoardService::find}, which asserts READ or throws).
 */
class BoardGroupService {
	private const MAX_NAME_LENGTH = 100;
	private const MAX_GROUPS_PER_USER = 100;

	public function __construct(
		private BoardGroupMapper $groupMapper,
		private BoardGroupMemberMapper $memberMapper,
		private BoardService $boardService,
	) {
	}

	/**
	 * All of the user's folders, in nav order, each with its board-id list (the
	 * boards the user has filed into it that they can still READ). Boards not in
	 * any folder are Ungrouped and are surfaced on the board-list payload
	 * instead (via {@see self::groupIdByBoard()}), not here.
	 *
	 * @return list<array{id: int, name: string, sort: int, boardIds: int[]}>
	 * @throws \OCP\DB\Exception
	 */
	public function listGroups(string $uid): array {
		$groups = $this->groupMapper->findByUser($uid);
		if ($groups === []) {
			return [];
		}

		$readableBoardIds = $this->readableBoardIds($uid);
		$membersByGroup = $this->membersByGroup($uid, $readableBoardIds);

		$out = [];
		foreach ($groups as $group) {
			$id = $group->getId();
			$out[] = $group->jsonSerialize() + [
				'boardIds' => $membersByGroup[$id] ?? [],
			];
		}
		return $out;
	}

	/**
	 * The batched board_id => group_id map for the board-list payload: which
	 * folder (if any) each of the user's readable boards is filed under, in ONE
	 * `WHERE uid = ? AND board_id IN (...)`. A board absent from the map is
	 * Ungrouped. The caller passes the exact readable board-id set, so this can
	 * never leak a membership for a board the user cannot see.
	 *
	 * @param int[] $boardIds the readable board-id set
	 * @return array<int, int> board_id => group_id
	 * @throws \OCP\DB\Exception
	 */
	public function groupIdByBoard(string $uid, array $boardIds): array {
		return $this->memberMapper->findGroupIdsByBoards($uid, $boardIds);
	}

	/**
	 * Creates a folder owned by the caller, appended after their existing
	 * folders.
	 *
	 * @throws InvalidInputException on an empty/oversized name or too many folders
	 * @throws \OCP\DB\Exception
	 */
	public function createGroup(string $uid, string $name): BoardGroup {
		$name = $this->validateName($name);

		if (count($this->groupMapper->findByUser($uid)) >= self::MAX_GROUPS_PER_USER) {
			throw new InvalidInputException('Too many folders');
		}

		$group = new BoardGroup();
		$group->setUid($uid);
		$group->setName($name);
		$group->setSort($this->groupMapper->maxSort($uid) + 1);
		return $this->groupMapper->insert($group);
	}

	/**
	 * Renames a folder the caller owns.
	 *
	 * @throws NotPermittedException if the folder is not the caller's
	 * @throws InvalidInputException on an empty/oversized name
	 * @throws \OCP\DB\Exception
	 */
	public function renameGroup(string $uid, int $groupId, string $name): BoardGroup {
		$group = $this->loadOwnedGroup($uid, $groupId);
		$group->setName($this->validateName($name));
		return $this->groupMapper->update($group);
	}

	/**
	 * Deletes a folder the caller owns and ungroups (never deletes) its boards.
	 *
	 * @throws NotPermittedException if the folder is not the caller's
	 * @throws \OCP\DB\Exception
	 */
	public function deleteGroup(string $uid, int $groupId): void {
		$group = $this->loadOwnedGroup($uid, $groupId);
		// Ungroup its boards first (they revert to Ungrouped), then drop the folder.
		$this->memberMapper->deleteByGroup($groupId, $uid);
		$this->groupMapper->delete($group);
	}

	/**
	 * Reorders the caller's folders to the given id order. Ids not owned by the
	 * caller are ignored; owned folders missing from the list keep their
	 * relative order after the listed ones.
	 *
	 * @param int[] $orderedGroupIds
	 * @return list<array{id: int, name: string, sort: int, boardIds: int[]}>
	 * @throws \OCP\DB\Exception
	 */
	public function reorderGroups(string $uid, array $orderedGroupIds): array {
		$groups = $this->groupMapper->findByUser($uid);
		/** @var array<int, BoardGroup> $byId */
		$byId = [];
		foreach ($groups as $group) {
			$byId[$group->getId()] = $group;
		}

		$sort = 0;
		$seen = [];
		foreach ($orderedGroupIds as $id) {
			$id = (int)$id;
			if (!isset($byId[$id]) || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$this->applySort($byId[$id], $sort++);
		}
		// Any owned folder the client omitted keeps a stable slot after the rest.
		foreach ($groups as $group) {
			if (!isset($seen[$group->getId()])) {
				$this->applySort($group, $sort++);
			}
		}

		return $this->listGroups($uid);
	}

	/**
	 * Files a board the caller can READ into one of the caller's folders
	 * (idempotent upsert - a board moves folders or joins one, and is in at most
	 * one folder per user).
	 *
	 * @throws NotPermittedException if the folder is not the caller's or the board is unreadable
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the board does not exist or is deleted
	 * @throws \OCP\DB\Exception
	 */
	public function assignBoard(string $uid, int $groupId, int $boardId): void {
		$this->loadOwnedGroup($uid, $groupId);
		// READ-gate the board: find() asserts READ (or throws NotPermitted /
		// DoesNotExist), so a user can never file a board they cannot see.
		$this->boardService->find($boardId, $uid);

		$existing = $this->memberMapper->findForBoard($uid, $boardId);
		if ($existing !== null) {
			if ($existing->getGroupId() === $groupId) {
				return;
			}
			$existing->setGroupId($groupId);
			$this->memberMapper->update($existing);
			return;
		}

		$member = new BoardGroupMember();
		$member->setUid($uid);
		$member->setGroupId($groupId);
		$member->setBoardId($boardId);
		try {
			$this->memberMapper->insert($member);
		} catch (\OCP\DB\Exception $e) {
			// Concurrent assign lost the unique (uid, board_id) race - the row
			// exists now, so re-file it to the requested folder to converge.
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$row = $this->memberMapper->findForBoard($uid, $boardId);
			if ($row !== null && $row->getGroupId() !== $groupId) {
				$row->setGroupId($groupId);
				$this->memberMapper->update($row);
			}
		}
	}

	/**
	 * Removes a board from whatever folder the caller filed it in (back to
	 * Ungrouped). Idempotent - unfiling a board that isn't filed is a no-op. No
	 * board READ check: this only ever removes the caller's OWN membership row.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function unassignBoard(string $uid, int $boardId): void {
		$existing = $this->memberMapper->findForBoard($uid, $boardId);
		if ($existing !== null) {
			$this->memberMapper->delete($existing);
		}
	}

	/**
	 * @throws NotPermittedException if the folder does not exist or is not the caller's
	 * @throws \OCP\DB\Exception
	 */
	private function loadOwnedGroup(string $uid, int $groupId): BoardGroup {
		$group = $this->groupMapper->findOwned($groupId, $uid);
		if ($group === null) {
			throw new NotPermittedException('Folder not found');
		}
		return $group;
	}

	/**
	 * Persist a folder's sort only when it actually changed - Entity::update
	 * would no-op an unchanged value anyway, but skipping avoids a needless call.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function applySort(BoardGroup $group, int $sort): void {
		if ($group->getSort() === $sort) {
			return;
		}
		$group->setSort($sort);
		$this->groupMapper->update($group);
	}

	/**
	 * The caller's readable, ACTIVE board-id set. Reused to filter folder
	 * membership so a folder never surfaces a board the user has since lost
	 * access to - or one they have archived (#10126): a shelved board drops out
	 * of the folder listing (its membership row is kept, so unarchiving restores
	 * it). The boards page keeps its own Archived tab, built from the boards-list
	 * payload, not from here.
	 *
	 * @return int[]
	 * @throws \OCP\DB\Exception
	 */
	private function readableBoardIds(string $uid): array {
		return array_map(
			static fn ($b): int => $b->getId(),
			$this->boardService->findAllActive($uid)
		);
	}

	/**
	 * Group the caller's memberships (restricted to readable boards) into
	 * group_id => board_id[]. Built from the same batched lookup the payload
	 * uses, so it is N+1-free.
	 *
	 * @param int[] $readableBoardIds
	 * @return array<int, int[]> group_id => board_id[]
	 * @throws \OCP\DB\Exception
	 */
	private function membersByGroup(string $uid, array $readableBoardIds): array {
		$byBoard = $this->memberMapper->findGroupIdsByBoards($uid, $readableBoardIds);
		$byGroup = [];
		foreach ($byBoard as $boardId => $groupId) {
			$byGroup[$groupId][] = $boardId;
		}
		return $byGroup;
	}

	/**
	 * @throws InvalidInputException on an empty or over-long name
	 */
	private function validateName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidInputException('A folder name is required');
		}
		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidInputException('Folder name is too long');
		}
		return $name;
	}
}

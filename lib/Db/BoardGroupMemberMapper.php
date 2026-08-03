<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_board_group_members` - which board sits in which folder,
 * per user.
 *
 * @template-extends QBMapper<BoardGroupMember>
 */
class BoardGroupMemberMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_board_group_members', BoardGroupMember::class);
	}

	/**
	 * The membership row for one (user, board), or null.
	 *
	 * @throws Exception
	 */
	public function findForBoard(string $uid, int $boardId): ?BoardGroupMember {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * The group id each of the given boards is filed under, for one user, as a
	 * map board_id => group_id. This is the ONE batched lookup that surfaces the
	 * per-user group on the board-list payload - a single
	 * `WHERE uid = ? AND board_id IN (...)`, never one query per board. An
	 * empty board-id set short-circuits to no query.
	 *
	 * @param int[] $boardIds
	 * @return array<int, int> board_id => group_id
	 * @throws Exception
	 */
	public function findGroupIdsByBoards(string $uid, array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id', 'group_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['board_id']] = (int)$row['group_id'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Removes every membership of a folder (a folder delete ungroups its boards
	 * without deleting the boards). Scoped by uid too as a belt-and-braces guard.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByGroup(int $groupId, string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		return $qb->executeStatement();
	}

	/**
	 * Removes every membership referencing a board, across all users - cascade
	 * for a board purge so a deleted board never lingers in anyone's folder.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByBoard(int $boardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}

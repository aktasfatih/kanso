<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_board_pins` - which boards a user has pinned (#3632).
 *
 * @template-extends QBMapper<BoardPin>
 */
class BoardPinMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_board_pins', BoardPin::class);
	}

	/**
	 * Pins a board for a user. Idempotent per (uid, board): a repeat pin of the
	 * same pair is a no-op, swallowing the unique-constraint violation (mirrors
	 * {@see ProjectCardMapper::add}).
	 *
	 * @throws Exception on any DB error other than the unique-constraint clash
	 */
	public function pin(string $uid, int $boardId): void {
		$row = new BoardPin();
		$row->setUid($uid);
		$row->setBoardId($boardId);

		try {
			$this->insert($row);
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// The board is already pinned - the idempotent success case.
				return;
			}
			throw $e;
		}
	}

	/**
	 * Unpins a board for a user. Idempotent - unpinning a board that is not
	 * pinned deletes nothing.
	 *
	 * @throws Exception
	 */
	public function unpin(string $uid, int $boardId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Every board id the user has pinned.
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function findPinnedBoardIds(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->orderBy('board_id', 'ASC');

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['board_id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Which of the given boards the user has pinned, as a map board_id => true.
	 * This is the ONE batched lookup that surfaces the per-user `pinned` flag on
	 * the board-list payload - a single `WHERE uid = ? AND board_id IN (...)`,
	 * never one query per board. An empty board-id set short-circuits to no
	 * query.
	 *
	 * @param int[] $boardIds
	 * @return array<int, bool> board_id => true (only pinned boards appear)
	 * @throws Exception
	 */
	public function pinnedMap(string $uid, array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['board_id']] = true;
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Removes every pin referencing a board, across all users - cascade for a
	 * board purge so a deleted board never lingers in anyone's pins.
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

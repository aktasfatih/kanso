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
 * Mapper for `kanso_stacks`.
 *
 * @template-extends QBMapper<Stack>
 */
class StackMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_stacks', Stack::class);
	}

	/**
	 * @throws DoesNotExistException if the stack does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): Stack {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All non-deleted stacks of a board in display order.
	 *
	 * @return Stack[]
	 * @throws Exception
	 */
	public function findByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The given non-deleted stacks of a board - the delta-sync counterpart of
	 * {@see self::findByBoard()}, restricted to an explicit id set (the stacks a
	 * `?since=` window touched). Board-scoped and non-deleted like the full-board
	 * read, so a stack deleted between the client's cursor and now is simply
	 * absent from the result - the controller then emits it as a `stacks.remove`.
	 * An empty id set short-circuits (never emit `IN ()`, which is invalid SQL).
	 *
	 * @param int[] $ids
	 * @return Stack[]
	 * @throws Exception
	 */
	public function findByIds(int $boardId, array $ids): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * The first non-deleted stack of a board carrying the given workflow role
	 * (in display order), or null. Used to resolve auto-move targets (e.g. the
	 * "In review" or "Done" column) without a separate config surface.
	 *
	 * @throws Exception
	 */
	public function findByBoardAndRole(int $boardId, int $role): ?Stack {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC')
			->setMaxResults(1);

		$stacks = $this->findEntities($qb);
		return $stacks[0] ?? null;
	}
}

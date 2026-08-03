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
 * Mapper for `kanso_board_groups` - a user's board folders.
 *
 * @template-extends QBMapper<BoardGroup>
 */
class BoardGroupMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_board_groups', BoardGroup::class);
	}

	/**
	 * All folders of a user, in nav order (sort, then id as a stable tiebreak).
	 *
	 * @return BoardGroup[]
	 * @throws Exception
	 */
	public function findByUser(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->orderBy('sort', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * One folder scoped to its owner, or null. Scoping by uid here is the
	 * horizontal-privesc guard: a crafted id belonging to another user resolves
	 * to null, never their row.
	 *
	 * @throws Exception
	 */
	public function findOwned(int $id, string $uid): ?BoardGroup {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * The current max sort value among a user's folders, or -1 if they have
	 * none - a new folder appends at max+1.
	 *
	 * @throws Exception
	 */
	public function maxSort(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort'))
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return $max === false || $max === null ? -1 : (int)$max;
	}
}

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
 * Mapper for `kanso_projects`.
 *
 * @template-extends QBMapper<Project>
 */
class ProjectMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_projects', Project::class);
	}

	/**
	 * @throws DoesNotExistException if the project does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): Project {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Every project owned by the user, in title order.
	 *
	 * @return Project[]
	 * @throws Exception
	 */
	public function findByOwner(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)))
			->orderBy('title', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The viewer's OWN projects among the given ids, in one query - the board
	 * payload's project lookup. Projects are owner-only, so restricting to
	 * `owner = uid` is the whole ACL: a project someone else owns is invisible,
	 * and its title/color must not leak through a shared card.
	 *
	 * @param int[] $ids
	 * @return Project[]
	 * @throws Exception
	 */
	public function findByIdsForOwner(array $ids, string $uid): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values($ids), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($uid)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}
}

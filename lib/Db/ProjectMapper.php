<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Access\ProjectAccess;
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
	public function __construct(
		IDBConnection $db,
		private ProjectAccess $projectAccess,
	) {
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
	 * Every project the user owns or was shared, in title order.
	 *
	 * @return Project[]
	 * @throws Exception
	 */
	public function findAccessible(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('title', 'ASC')
			->addOrderBy('id', 'ASC');
		$this->projectAccess->restrict($qb, '', $uid);

		return $this->findEntities($qb);
	}

	/**
	 * @return Project[]
	 * @throws Exception
	 */
	public function findOwnedBy(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)));

		return $this->findEntities($qb);
	}

	/** @throws Exception */
	public function clearLeadByUser(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('lead', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('lead', $qb->createNamedParameter($uid)));

		return $qb->executeStatement();
	}

	/**
	 * The projects among $ids the user can reach, in one query - the board
	 * payload's project lookup. Anything else stays invisible: its title and
	 * color must not leak through a shared card.
	 *
	 * @param int[] $ids
	 * @return Project[]
	 * @throws Exception
	 */
	public function findAccessibleByIds(array $ids, string $uid): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values($ids), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');
		$this->projectAccess->restrict($qb, '', $uid);

		return $this->findEntities($qb);
	}
}

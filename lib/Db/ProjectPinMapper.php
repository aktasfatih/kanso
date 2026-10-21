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
 * @template-extends QBMapper<ProjectPin>
 */
class ProjectPinMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_project_pins', ProjectPin::class);
	}

	/**
	 * Idempotent: pinning an already pinned project is a no-op.
	 *
	 * @throws Exception
	 */
	public function pin(string $uid, int $projectId): void {
		$row = new ProjectPin();
		$row->setUid($uid);
		$row->setProjectId($projectId);

		try {
			$this->insert($row);
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/** @throws Exception */
	public function unpin(string $uid, int $projectId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * @return array<int, true> pinned project id => true
	 * @throws Exception
	 */
	public function pinnedMap(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('project_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['project_id']] = true;
		}
		$result->closeCursor();

		return $map;
	}

	/** @throws Exception */
	public function deleteByProject(int $projectId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}
}

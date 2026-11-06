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
 * Mapper for `kanso_project_shares`.
 *
 * @template-extends QBMapper<ProjectShare>
 */
class ProjectShareMapper extends QBMapper {
	private const CHUNK_SIZE = 1000;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_project_shares', ProjectShare::class);
	}

	/**
	 * @throws DoesNotExistException if no sharing rule has this id
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): ProjectShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @param int $participantType one of the Acl::TYPE_* constants
	 * @throws Exception
	 */
	public function findByParticipant(int $projectId, int $participantType, string $participant): ?ProjectShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('participant_type', $qb->createNamedParameter($participantType, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($participant)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return ProjectShare[]
	 * @throws Exception
	 */
	public function findByProject(int $projectId): array {
		return $this->findByProjects([$projectId]);
	}

	/**
	 * @param int[] $projectIds
	 * @return ProjectShare[]
	 * @throws Exception
	 */
	public function findByProjects(array $projectIds): array {
		if ($projectIds === []) {
			return [];
		}
		$shares = [];
		foreach (array_chunk($projectIds, self::CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('project_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC');
			array_push($shares, ...$this->findEntities($qb));
		}

		return $shares;
	}

	/**
	 * @param int $participantType one of the Acl::TYPE_* constants
	 * @throws Exception
	 */
	public function deleteByParticipant(int $participantType, string $participant): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('participant_type', $qb->createNamedParameter($participantType, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($participant)));

		$qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function deleteByProject(int $projectId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}
}

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
 * Mapper for `kanso_board_acl`.
 *
 * @template-extends QBMapper<Acl>
 */
class AclMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_board_acl', Acl::class);
	}

	/**
	 * @throws DoesNotExistException if no sharing rule has this id
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): Acl {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * The board's sharing rule addressing this exact participant, or null.
	 *
	 * @param int $participantType one of the Acl::TYPE_* constants
	 * @throws Exception
	 */
	public function findByParticipant(int $boardId, int $participantType, string $participant): ?Acl {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('participant_type', $qb->createNamedParameter($participantType, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($participant)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * All sharing rules of a board.
	 *
	 * @return Acl[]
	 * @throws Exception
	 */
	public function findByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * All sharing rules of MANY boards in one query (`board_id IN (...)`), for
	 * batched permission computation over a board set — never call
	 * {@see self::findByBoard()} in a loop (that is the N+1 this avoids).
	 *
	 * @param int[] $boardIds
	 * @return Acl[]
	 * @throws Exception
	 */
	public function findByBoards(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $this->findEntities($qb);
	}
}

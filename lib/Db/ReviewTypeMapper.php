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
 * Mapper for `kanso_review_types`.
 *
 * @template-extends QBMapper<ReviewType>
 */
class ReviewTypeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_review_types', ReviewType::class);
	}

	/**
	 * @throws DoesNotExistException if the review type does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): ReviewType {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All review types of a board in creation order.
	 *
	 * @return ReviewType[]
	 * @throws Exception
	 */
	public function findByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Review-type id => stage map for a board, in ONE query - the lookup the
	 * gating fold needs (see {@see \OCA\Kanso\Service\ReviewService}). Untyped
	 * reviews (review_type_id = 0) are implicitly stage 0 and are NOT keyed here;
	 * callers must default a missing id to stage 0.
	 *
	 * @return array<int, int> map of reviewTypeId => stage
	 * @throws Exception
	 */
	public function stageMapForBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'stage')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['id']] = (int)$row['stage'];
		}
		$result->closeCursor();

		return $map;
	}
}

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
 * Mapper for `kanso_card_fields`, the per-board custom-field definitions.
 *
 * @template-extends QBMapper<CardField>
 */
class CardFieldMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_fields', CardField::class);
	}

	/**
	 * @throws DoesNotExistException if the field does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): CardField {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All field definitions of a board, in their fractional sort-key order (NOT
	 * by id - reordering is a single-row sort_key UPDATE, the #1 perf bet).
	 *
	 * @return CardField[]
	 * @throws Exception
	 */
	public function findByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The highest (last) sort key currently used on the board, or null when the
	 * board has no fields yet - the anchor a new field is appended after.
	 *
	 * @throws Exception
	 */
	public function lastSortKey(int $boardId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('sort_key')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'DESC')
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$key = $result->fetchOne();
		$result->closeCursor();

		return $key === false ? null : (string)$key;
	}
}

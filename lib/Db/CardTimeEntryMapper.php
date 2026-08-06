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
 * Mapper for `kanso_card_time_entries`.
 *
 * @template-extends QBMapper<CardTimeEntry>
 */
class CardTimeEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_time_entries', CardTimeEntry::class);
	}

	/**
	 * A single time entry by id.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if it does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): CardTimeEntry {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * A card's time entries, newest first.
	 *
	 * @return CardTimeEntry[]
	 * @throws Exception
	 */
	public function findByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Removes every time-entry ROW of a card - the cascade when a card is
	 * permanently purged.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Total seconds logged on a card - powers the card-detail `timeSpent` total
	 * without loading the rows. A card with no entries sums to 0.
	 *
	 * @throws Exception
	 */
	public function sumSecondsByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('seconds'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['total'] ?? 0);
	}
}

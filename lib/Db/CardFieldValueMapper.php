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
 * Mapper for `kanso_card_field_values`, the per-card custom-field values. One
 * row per (card_id, field_id) - a unique index enforces it, so a write is an
 * upsert.
 *
 * @template-extends QBMapper<CardFieldValue>
 */
class CardFieldValueMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_field_values', CardFieldValue::class);
	}

	/**
	 * Every field value of a card.
	 *
	 * @return CardFieldValue[]
	 * @throws Exception
	 */
	public function findByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * The card's value for one field, or null when it has none.
	 *
	 * @throws Exception
	 */
	public function findByCardAndField(int $cardId, int $fieldId): ?CardFieldValue {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Removes a single (card, field) value - the "clear a value" path.
	 *
	 * @return int number of deleted rows (0 when there was no value)
	 * @throws Exception
	 */
	public function deleteByCardAndField(int $cardId, int $fieldId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Removes every value referencing a field - the cascade when a field
	 * definition is deleted.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByField(int $fieldId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Removes every field value of a card - the cascade for a card purge.
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
}

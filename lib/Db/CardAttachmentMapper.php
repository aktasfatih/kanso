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
 * Mapper for `kanso_card_attachments`.
 *
 * @template-extends QBMapper<CardAttachment>
 */
class CardAttachmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_attachments', CardAttachment::class);
	}

	/**
	 * A single attachment by id.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if it does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): CardAttachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * A card's attachments, oldest first.
	 *
	 * @return CardAttachment[]
	 * @throws Exception
	 */
	public function findByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Removes every attachment ROW of a card - the DB half of the cascade when a
	 * card is permanently purged. The bytes are removed separately (the
	 * app-data objects), so callers must drop those too; see
	 * {@see \OCA\Kanso\Service\CardAttachmentService::deleteAllForCard()}.
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
	 * Number of attachments on a card - powers the card-detail count without
	 * loading the rows.
	 *
	 * @throws Exception
	 */
	public function countByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}
}

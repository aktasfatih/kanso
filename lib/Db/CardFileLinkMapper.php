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
 * @template-extends QBMapper<CardFileLink>
 */
class CardFileLinkMapper extends QBMapper {
	public const MAX_PER_CARD = 50;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_file_links', CardFileLink::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): CardFileLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @return CardFileLink[]
	 * @throws Exception
	 */
	public function findByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults(self::MAX_PER_CARD);

		return $this->findEntities($qb);
	}

	/**
	 * Unbounded on purpose: the cap check must see rows beyond MAX_PER_CARD.
	 *
	 * @throws Exception
	 */
	public function countByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'link_count'))
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}
}

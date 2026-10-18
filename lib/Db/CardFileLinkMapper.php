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
 * Mapper for `kanso_card_file_links` (KANSO-307).
 *
 * @template-extends QBMapper<CardFileLink>
 */
class CardFileLinkMapper extends QBMapper {
	/**
	 * Hard cap on the file links one card may carry. Enforced on the write side
	 * by {@see \OCA\Kanso\Service\CardFileLinkService::addLink()} and,
	 * independently, as the read bound of {@see self::findByCard()} - so a card
	 * holding more rows (legacy data, a lost race) still answers a bounded list.
	 */
	public const MAX_PER_CARD = 50;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_file_links', CardFileLink::class);
	}

	/**
	 * A single file link by id.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if it does not exist
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
	 * A card's file links, oldest first, bounded to {@see self::MAX_PER_CARD}.
	 *
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
	 * How many file links a card carries. Unbounded on purpose - it is the cap
	 * check, so it must see rows beyond {@see self::MAX_PER_CARD} too.
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

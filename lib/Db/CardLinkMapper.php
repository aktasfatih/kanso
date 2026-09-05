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
 * Mapper for `kanso_card_links`.
 *
 * @template-extends QBMapper<CardLink>
 */
class CardLinkMapper extends QBMapper {
	/**
	 * Hard cap on the links one card may carry. Enforced on the write side by
	 * {@see \OCA\Kanso\Service\CardLinkService::addLink()} and, independently, as
	 * the read bound of {@see self::findByCard()} - so a card that somehow holds
	 * more rows (legacy data) still cannot make a card read do unbounded work.
	 */
	public const MAX_PER_CARD = 20;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_links', CardLink::class);
	}

	/**
	 * A single link by id.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if it does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): CardLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * A card's links, oldest first, bounded to {@see self::MAX_PER_CARD}. The
	 * bound matters: the caller refreshes each returned link with a blocking
	 * outbound GET, so an unbounded row count would be an unbounded card read.
	 *
	 * @return CardLink[]
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
	 * How many links a card carries. Unbounded on purpose - it is the cap check,
	 * so it must see rows beyond {@see self::MAX_PER_CARD} too.
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

	/**
	 * Board-scoped reverse lookup: links on this board matching any of the
	 * candidate URLs, restricted to alive (non-deleted, non-archived) cards.
	 * Powers the GitHub webhook's issue events, where the delivery names only
	 * the issue URL - not a card - so the card is found via its attached link.
	 * Archived cards are deliberately excluded: they are off the active board,
	 * so auto-moving them would be invisible and surprising.
	 *
	 * @param string[] $urls candidate URL spellings of one resource (e.g. with/without trailing slash)
	 * @return CardLink[]
	 * @throws Exception
	 */
	public function findByBoardAndUrls(int $boardId, array $urls): array {
		if ($urls === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('l.*')
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'kanso_cards', 'c', $qb->expr()->eq('l.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->in('l.url', $qb->createNamedParameter($urls, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('l.id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Whether ANY card on this board - alive, archived or trashed - carries one
	 * of the candidate URLs as a link. The webhook's issue-intake dedup check:
	 * unlike {@see findByBoardAndUrls} it deliberately ignores the card's
	 * state, so a redelivered `opened` event never re-creates a card whose
	 * original was archived or moved to the trash. (A purged card takes its
	 * link rows with it - re-creation after a purge is accepted.)
	 *
	 * @param string[] $urls candidate URL spellings of one resource
	 * @throws Exception
	 */
	public function existsByBoardAndUrls(int $boardId, array $urls): bool {
		if ($urls === []) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('l.id')
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'kanso_cards', 'c', $qb->expr()->eq('l.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('l.url', $qb->createNamedParameter($urls, IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetchOne();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Removes every link of a card - cascade for a card purge.
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

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
 * Mapper for `kanso_card_relations`.
 *
 * @template-extends QBMapper<CardRelation>
 */
class CardRelationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_relations', CardRelation::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): CardRelation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** @throws Exception */
	public function exists(int $cardId, int $otherCardId, string $type): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('other_card_id', $qb->createNamedParameter($otherCardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Relations where $cardId is the source (card_id), joined to the OTHER card.
	 *
	 * @return list<array{id: int, type: string, otherCardId: int, otherTitle: string, otherDone: bool}>
	 * @throws Exception
	 */
	public function findOutgoing(int $cardId): array {
		return $this->joinedRows('card_id', 'other_card_id', $cardId);
	}

	/**
	 * Relations where $cardId is the target (other_card_id), joined to the
	 * source card - this is where "blocked by" comes from.
	 *
	 * @return list<array{id: int, type: string, otherCardId: int, otherTitle: string, otherDone: bool}>
	 * @throws Exception
	 */
	public function findIncoming(int $cardId): array {
		return $this->joinedRows('other_card_id', 'card_id', $cardId);
	}

	/**
	 * Shared body of findOutgoing/findIncoming: rows where $matchCol = $cardId,
	 * joined to the card named by $otherCol (the card at the far end).
	 *
	 * @return list<array{id: int, type: string, otherCardId: int, otherTitle: string, otherDone: bool}>
	 * @throws Exception
	 */
	private function joinedRows(string $matchCol, string $otherCol, int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'r.type')
			->selectAlias('c.id', 'other_id')
			->selectAlias('c.title', 'other_title')
			->selectAlias('c.done_at', 'other_done_at')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'kanso_cards', 'c', $qb->expr()->eq('r.' . $otherCol, 'c.id'))
			->where($qb->expr()->eq('r.' . $matchCol, $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('r.id', 'ASC');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'id' => (int)$row['id'],
				'type' => (string)$row['type'],
				'otherCardId' => (int)$row['other_id'],
				'otherTitle' => (string)$row['other_title'],
				'otherDone' => ((int)$row['other_done_at']) > 0,
			];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Every `blocks` edge on the board as [from, to] pairs - feeds the cycle
	 * check when a new blocks relation is proposed.
	 *
	 * @return list<array{from: int, to: int}>
	 * @throws Exception
	 */
	public function findBlocksEdgesByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('card_id', 'other_card_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(CardRelation::TYPE_BLOCKS)));

		$result = $qb->executeQuery();
		$edges = [];
		while (($row = $result->fetch()) !== false) {
			$edges[] = ['from' => (int)$row['card_id'], 'to' => (int)$row['other_card_id']];
		}
		$result->closeCursor();
		return $edges;
	}

	/**
	 * Card ids on the board that are blocked by at least one NOT-done card -
	 * powers the tile "blocked" badge in one board-scoped query.
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function blockedCardIdsByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('r.other_card_id')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'kanso_cards', 'blocker', $qb->expr()->eq('r.card_id', 'blocker.id'))
			->where($qb->expr()->eq('r.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('r.type', $qb->createNamedParameter(CardRelation::TYPE_BLOCKS)))
			->andWhere($qb->expr()->eq('blocker.done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('blocker.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['other_card_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Removes every relation touching a card (either side) - cascade for a card
	 * purge.
	 *
	 * @throws Exception
	 */
	public function deleteByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('other_card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)),
			));

		return $qb->executeStatement();
	}
}

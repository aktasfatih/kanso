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
	 * @return list<array{id: int, type: string, otherCardId: int, otherTitle: string, otherDone: bool, otherVisibility: ?string, otherCreatorRole: ?string, otherOwner: string}>
	 * @throws Exception
	 */
	public function findOutgoing(int $cardId): array {
		return $this->joinedRows('card_id', 'other_card_id', $cardId);
	}

	/**
	 * Relations where $cardId is the target (other_card_id), joined to the
	 * source card - this is where "blocked by" comes from.
	 *
	 * @return list<array{id: int, type: string, otherCardId: int, otherTitle: string, otherDone: bool, otherVisibility: ?string, otherCreatorRole: ?string, otherOwner: string}>
	 * @throws Exception
	 */
	public function findIncoming(int $cardId): array {
		return $this->joinedRows('other_card_id', 'card_id', $cardId);
	}

	/**
	 * Shared body of findOutgoing/findIncoming: rows where $matchCol = $cardId,
	 * joined to the card named by $otherCol (the card at the far end).
	 *
	 * @return list<array{id: int, type: string, otherCardId: int, otherTitle: string, otherDone: bool, otherVisibility: ?string, otherCreatorRole: ?string, otherOwner: string}>
	 * @throws Exception
	 */
	private function joinedRows(string $matchCol, string $otherCol, int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.id', 'r.type')
			->selectAlias('c.id', 'other_id')
			->selectAlias('c.title', 'other_title')
			->selectAlias('c.done_at', 'other_done_at')
			// The counterpart's visibility triple rides along so the service
			// can mask hidden counterparts without a per-row card fetch (#3743).
			->selectAlias('c.visibility', 'other_visibility')
			->selectAlias('c.creator_role', 'other_creator_role')
			->selectAlias('c.owner', 'other_owner')
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
				'otherVisibility' => $row['other_visibility'] !== null ? (string)$row['other_visibility'] : null,
				'otherCreatorRole' => $row['other_creator_role'] !== null ? (string)$row['other_creator_role'] : null,
				'otherOwner' => (string)$row['other_owner'],
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
	 * The same `blocks` edges as {@see self::findBlocksEdgesByBoard()}, but with
	 * BOTH endpoints' visibility triples joined in - the board-scoped edge list
	 * behind the timeline's dependency arrows.
	 *
	 * Why the triples ride along: the caller must mask edges per viewer, and a
	 * board can hold hundreds of edges - re-fetching two cards per edge would
	 * turn one arrow layer into an N+1. Same trick as {@see self::joinedRows()}:
	 * ship the (visibility, creator_role, owner) triple and let
	 * {@see \OCA\Kanso\Service\CardVisibilityScope::isVisibleTo()} decide in PHP,
	 * off the exact rule the SQL scope encodes.
	 *
	 * Deliberately NOT merged into findBlocksEdgesByBoard(): that one feeds the
	 * cycle check, which must see the graph WHOLE (an unmasked edge still
	 * closes a cycle) and would only pay for the joins.
	 *
	 * @return list<array{from: int, to: int, fromVisibility: ?string, fromCreatorRole: ?string, fromOwner: string, toVisibility: ?string, toCreatorRole: ?string, toOwner: string}>
	 * @throws Exception
	 */
	public function findBlocksEdgesWithVisibilityByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias('r.card_id', 'from_id')
			->selectAlias('r.other_card_id', 'to_id')
			->selectAlias('src.visibility', 'from_visibility')
			->selectAlias('src.creator_role', 'from_creator_role')
			->selectAlias('src.owner', 'from_owner')
			->selectAlias('dst.visibility', 'to_visibility')
			->selectAlias('dst.creator_role', 'to_creator_role')
			->selectAlias('dst.owner', 'to_owner')
			->from($this->getTableName(), 'r')
			// Both ends joined so a soft-deleted endpoint drops the edge here
			// rather than leaving the client an arrow to a card it never got.
			->innerJoin('r', 'kanso_cards', 'src', $qb->expr()->eq('r.card_id', 'src.id'))
			->innerJoin('r', 'kanso_cards', 'dst', $qb->expr()->eq('r.other_card_id', 'dst.id'))
			->where($qb->expr()->eq('r.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('r.type', $qb->createNamedParameter(CardRelation::TYPE_BLOCKS)))
			->andWhere($qb->expr()->eq('src.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('dst.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			// Stable output: the payload is diffed client-side, so a fixed order
			// keeps an unchanged graph from looking changed.
			->orderBy('r.card_id', 'ASC')
			->addOrderBy('r.other_card_id', 'ASC');

		$result = $qb->executeQuery();
		$edges = [];
		while (($row = $result->fetch()) !== false) {
			$edges[] = [
				'from' => (int)$row['from_id'],
				'to' => (int)$row['to_id'],
				'fromVisibility' => $row['from_visibility'] !== null ? (string)$row['from_visibility'] : null,
				'fromCreatorRole' => $row['from_creator_role'] !== null ? (string)$row['from_creator_role'] : null,
				'fromOwner' => (string)$row['from_owner'],
				'toVisibility' => $row['to_visibility'] !== null ? (string)$row['to_visibility'] : null,
				'toCreatorRole' => $row['to_creator_role'] !== null ? (string)$row['to_creator_role'] : null,
				'toOwner' => (string)$row['to_owner'],
			];
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

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
 * Mapper for `kanso_card_labels`, the card/label assignment rows.
 *
 * @template-extends QBMapper<CardLabel>
 */
class CardLabelMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_labels', CardLabel::class);
	}

	/**
	 * Label ids of every non-deleted card on a board, as one query joining
	 * through `kanso_cards` - the board payload stays a fixed number of
	 * queries no matter how many cards carry labels.
	 *
	 * @return array<int, int[]> map of cardId => labelIds in assignment order
	 * @throws Exception
	 */
	public function findLabelIdsByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cl.card_id', 'cl.label_id')
			->from($this->getTableName(), 'cl')
			->innerJoin('cl', 'kanso_cards', 'c', $qb->expr()->eq('cl.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('cl.id', 'ASC');

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['card_id']][] = (int)$row['label_id'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Open (non-deleted) card counts grouped by label for a board - the
	 * "cards per label" board-stats aggregate. Joins through `kanso_cards`,
	 * grouped by label_id.
	 *
	 * @return list<array{labelId: int, count: int}>
	 * @throws Exception
	 */
	public function countByLabelForBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cl.label_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 'cl')
			->innerJoin('cl', 'kanso_cards', 'c', $qb->expr()->eq('cl.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('cl.label_id');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['labelId' => (int)$row['label_id'], 'count' => (int)$row['cnt']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The project-analytics twin of {@see self::countByLabelForBoard()} - label
	 * counts over an explicit card id set instead of a board. The caller supplies
	 * ONLY ACL-resolved project card ids (see
	 * {@see \OCA\Kanso\Db\ProjectCardMapper::findCardsInProjectAndBoards}), so
	 * there is no board scope and no cross-board leak. An empty set short-circuits
	 * (never emit an invalid `IN ()`).
	 *
	 * Grouping is by `(board_id, label_id)`, NOT label_id alone: a label id is
	 * only board-unique, so across a multi-board project two DISTINCT labels can
	 * share an id (e.g. "Bug" on board A and "Urgent" on board B are both id 5).
	 * Grouping by the id alone would silently sum them into one wrong bar; keying
	 * on the pair keeps each label distinct and lets the DTO layer label the bar
	 * with its board context. Each row carries `boardId` for that resolution.
	 *
	 * @param int[] $cardIds the viewer's ACL-resolved project card ids (empty → [])
	 * @return list<array{boardId: int, labelId: int, count: int}>
	 * @throws Exception
	 */
	public function countByLabelForCards(array $cardIds): array {
		if ($cardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('c.board_id', 'cl.label_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 'cl')
			->innerJoin('cl', 'kanso_cards', 'c', $qb->expr()->eq('cl.card_id', 'c.id'))
			->where($qb->expr()->in('c.id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('c.board_id')
			->addGroupBy('cl.label_id');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['boardId' => (int)$row['board_id'], 'labelId' => (int)$row['label_id'], 'count' => (int)$row['cnt']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Label ids assigned to one card, in assignment order.
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function findLabelIdsByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('label_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Whether the label is already assigned to the card.
	 *
	 * @throws Exception
	 */
	public function exists(int $cardId, int $labelId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('label_id', $qb->createNamedParameter($labelId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$exists = $result->fetchOne() !== false;
		$result->closeCursor();

		return $exists;
	}

	/**
	 * @throws Exception
	 */
	public function insertAssignment(int $cardId, int $labelId): CardLabel {
		$assignment = new CardLabel();
		$assignment->setCardId($cardId);
		$assignment->setLabelId($labelId);

		return $this->insert($assignment);
	}

	/**
	 * @return int number of deleted rows (0 when the assignment was absent)
	 * @throws Exception
	 */
	public function deleteAssignment(int $cardId, int $labelId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('label_id', $qb->createNamedParameter($labelId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Removes every label assignment of a card - cascade for a card purge.
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
	 * Removes every assignment of a label - the cascade for label deletion.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByLabel(int $labelId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('label_id', $qb->createNamedParameter($labelId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}

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
 * Mapper for `kanso_card_assignees`, the card/assignee assignment rows.
 *
 * Only user assignees exist for now, so every query filters on
 * `type = TYPE_USER` - group rows (if they ever appear) stay invisible
 * until group assignment ships.
 *
 * @template-extends QBMapper<CardAssignee>
 */
class CardAssigneeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_assignees', CardAssignee::class);
	}

	/**
	 * Assignee uids of every non-deleted card on a board, as one query
	 * joining through `kanso_cards` - the board payload stays a fixed number
	 * of queries no matter how many cards carry assignees.
	 *
	 * @return array<int, string[]> map of cardId => uids in assignment order
	 * @throws Exception
	 */
	public function findUserIdsByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ca.card_id', 'ca.participant')
			->from($this->getTableName(), 'ca')
			->innerJoin('ca', 'kanso_cards', 'c', $qb->expr()->eq('ca.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ca.type', $qb->createNamedParameter(CardAssignee::TYPE_USER, IQueryBuilder::PARAM_INT)))
			->orderBy('ca.id', 'ASC');

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['card_id']][] = (string)$row['participant'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Open (non-deleted) card counts grouped by assignee for a board - the
	 * "cards per assignee" board-stats aggregate. Joins through `kanso_cards`,
	 * user assignees only (TYPE_USER), grouped by participant.
	 *
	 * @return list<array{uid: string, count: int}>
	 * @throws Exception
	 */
	public function countByAssigneeForBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ca.participant')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 'ca')
			->innerJoin('ca', 'kanso_cards', 'c', $qb->expr()->eq('ca.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('ca.type', $qb->createNamedParameter(CardAssignee::TYPE_USER, IQueryBuilder::PARAM_INT)))
			->groupBy('ca.participant');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['uid' => (string)$row['participant'], 'count' => (int)$row['cnt']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The (participant, estimate) pairs of every open (non-deleted) card on a
	 * board that carries an estimate, one row per assignment - the source for
	 * the per-assignee estimate sum. Summing (and numeric filtering) is done in
	 * PHP after fetch, same dialect-clean approach as
	 * {@see \OCA\Kanso\Db\CardMapper::estimateByStack()}. User assignees only.
	 *
	 * @return list<array{uid: string, estimate: string}>
	 * @throws Exception
	 */
	public function estimateByAssigneeForBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ca.participant', 'c.estimate')
			->from($this->getTableName(), 'ca')
			->innerJoin('ca', 'kanso_cards', 'c', $qb->expr()->eq('ca.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('ca.type', $qb->createNamedParameter(CardAssignee::TYPE_USER, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('c.estimate'));

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['uid' => (string)$row['participant'], 'estimate' => (string)$row['estimate']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Assignee uids of one card, in assignment order.
	 *
	 * @return string[]
	 * @throws Exception
	 */
	public function findUserIdsByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('participant')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(CardAssignee::TYPE_USER, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$uids = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $uids;
	}

	/**
	 * Whether the user is already assigned to the card.
	 *
	 * @throws Exception
	 */
	public function exists(int $cardId, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(CardAssignee::TYPE_USER, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$exists = $result->fetchOne() !== false;
		$result->closeCursor();

		return $exists;
	}

	/**
	 * @throws Exception
	 */
	public function insertAssignment(int $cardId, string $uid): CardAssignee {
		$assignment = new CardAssignee();
		$assignment->setCardId($cardId);
		$assignment->setParticipant($uid);
		$assignment->setType(CardAssignee::TYPE_USER);

		return $this->insert($assignment);
	}

	/**
	 * Removes the user's assignments from every card of the board (stale-
	 * assignee cleanup after an unshare). DELETE cannot join in the NC query
	 * builder, so the board's card ids arrive via an uncorrelated subquery
	 * spliced in with createFunction - same pattern as
	 * {@see ChangeMapper::findPrunableIds()}. The subquery's parameter is
	 * registered on the outer builder, which is the one that executes.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByBoardAndUser(int $boardId, string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$sub = $this->db->getQueryBuilder();
		$sub->select('id')
			->from('kanso_cards')
			->where($sub->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		$qb->delete($this->getTableName())
			->where($qb->createFunction('card_id IN (' . $sub->getSQL() . ')'))
			->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(CardAssignee::TYPE_USER, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Removes every assignee of a card - cascade for a card purge.
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
	 * @return int number of deleted rows (0 when the assignment was absent)
	 * @throws Exception
	 */
	public function deleteAssignment(int $cardId, string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(CardAssignee::TYPE_USER, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}

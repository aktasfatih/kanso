<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_board_subscriptions`.
 *
 * @template-extends QBMapper<BoardSubscription>
 */
class BoardSubscriptionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_board_subscriptions', BoardSubscription::class);
	}

	/**
	 * The watch row for one (subscriber, board), or null.
	 *
	 * @throws Exception
	 */
	public function findOne(string $subscriber, int $boardId): ?BoardSubscription {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('subscriber', $qb->createNamedParameter($subscriber)))
			->andWhere($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * All watcher uids of a board, in subscription order.
	 *
	 * @return string[]
	 * @throws Exception
	 */
	public function findBoardSubscriberUids(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('subscriber')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$uids = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $uids;
	}

	/**
	 * Removes every subscription of a board - cascade for a board purge.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByBoard(int $boardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}

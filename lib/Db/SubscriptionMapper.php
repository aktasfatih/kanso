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
 * Mapper for `kanso_subscriptions`.
 *
 * @template-extends QBMapper<Subscription>
 */
class SubscriptionMapper extends QBMapper {
	public const THREAD_CARD = 0;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_subscriptions', Subscription::class);
	}

	/**
	 * The subscription row for one (subscriber, card, thread) scope, or null.
	 *
	 * @throws Exception
	 */
	public function findOne(string $subscriber, int $cardId, int $threadId): ?Subscription {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('subscriber', $qb->createNamedParameter($subscriber)))
			->andWhere($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('comment_thread_id', $qb->createNamedParameter($threadId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Active card-level watcher uids (thread 0), in subscription order.
	 *
	 * @return string[]
	 * @throws Exception
	 */
	public function findCardSubscriberUids(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('subscriber')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('comment_thread_id', $qb->createNamedParameter(self::THREAD_CARD, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(Subscription::STATE_SUBSCRIBED, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$uids = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $uids;
	}

	/**
	 * Uids to notify of activity in a thread: everyone watching the whole card
	 * (thread 0) plus everyone watching that specific thread, deduplicated,
	 * active only.
	 *
	 * @return string[]
	 * @throws Exception
	 */
	public function findNotifyUids(int $cardId, int $threadId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('subscriber')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(Subscription::STATE_SUBSCRIBED, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in(
				'comment_thread_id',
				$qb->createNamedParameter(
					array_values(array_unique([self::THREAD_CARD, $threadId])),
					IQueryBuilder::PARAM_INT_ARRAY
				)
			));

		$result = $qb->executeQuery();
		$uids = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $uids;
	}

	/**
	 * Removes every subscription of a card — cascade for a card purge.
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

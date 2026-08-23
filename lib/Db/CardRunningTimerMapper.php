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
 * Mapper for `kanso_card_running_timers` (#73). At most one running timer per
 * card (UNIQUE on card_id); {@see findByCard} is the hot lookup on start/stop.
 *
 * @template-extends QBMapper<CardRunningTimer>
 */
class CardRunningTimerMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_running_timers', CardRunningTimer::class);
	}

	/**
	 * The card's running timer, or throws if none is running.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if no timer is running
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByCard(int $cardId): CardRunningTimer {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Card ids on the board that currently have a running timer - powers the
	 * tile "timer running" badge in one board-scoped query (no N+1). Mirrors
	 * {@see RecurRuleMapper::findTemplateCardIdsByBoard()} in structure.
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function findCardIdsByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('card_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['card_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Removes the running timer of a card - the cascade when a card is purged and
	 * the normal drop when a timer is stopped. Safe when no timer is running.
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
	 * Removes every running timer of a board - cascade for a board purge.
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

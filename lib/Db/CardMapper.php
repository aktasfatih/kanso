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
 * Mapper for `kanso_cards`.
 *
 * @template-extends QBMapper<Card>
 */
class CardMapper extends QBMapper {
	/**
	 * Every column EXCEPT `description`. The description is deliberately
	 * excluded from board/stack listings — this is the charter's
	 * summary-payload performance bet: board endpoints stay small no matter
	 * how long card descriptions get. The description is loaded only by
	 * {@see CardMapper::find()} when a single card is opened.
	 */
	private const SUMMARY_COLUMNS = [
		'id',
		'board_id',
		'stack_id',
		'title',
		'sort_key',
		'duedate',
		'done_at',
		'archived',
		'owner',
		'created_at',
		'last_modified',
		'deleted_at',
	];

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_cards', Card::class);
	}

	/**
	 * Full row including the description — single-card detail fetch.
	 *
	 * @throws DoesNotExistException if the card does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): Card {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Summaries (no description) of all non-deleted cards on a board,
	 * grouped by stack and in display order.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findSummariesByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('stack_id', 'ASC')
			->addOrderBy('sort_key', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Summaries (no description) of all non-deleted cards in a stack, in
	 * display order.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findByStack(int $stackId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC');

		return $this->findEntities($qb);
	}
}

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
 * Mapper for `kanso_recur_rules`.
 *
 * @template-extends QBMapper<RecurRule>
 */
class RecurRuleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_recur_rules', RecurRule::class);
	}

	/**
	 * @throws DoesNotExistException if the rule does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): RecurRule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All rules on a board, newest first.
	 *
	 * @return RecurRule[]
	 * @throws Exception
	 */
	public function findByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'kanso_cards', 'c', $qb->expr()->eq('r.template_card_id', 'c.id'))
			->where($qb->expr()->eq('r.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('r.created_at', 'DESC')
			->addOrderBy('r.id', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Template card ids on the board that carry an ENABLED recurrence rule -
	 * powers the tile "recurring" badge in one board-scoped query (no N+1).
	 * Disabled/exhausted rules are excluded so only live schedules badge.
	 *
	 * @return int[]
	 * @throws Exception
	 */
	public function findTemplateCardIdsByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('template_card_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['template_card_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Does this template card carry a live (ENABLED) recurrence rule? Drives the
	 * "recurring" boolean on the full card detail payload so the open-card view
	 * matches the board tile for ALL viewers (the manager-only rule fetch can't
	 * be the sole driver). One indexed existence check - no rule object is
	 * loaded. Mirrors findTemplateCardIdsByBoard's enabled-only gate.
	 *
	 * @throws Exception
	 */
	public function hasEnabledRuleForCard(int $cardId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('template_card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * Removes every recurrence rule anchored on a template card - the cascade for
	 * a card purge. A rule whose template is hard-deleted can never spawn again
	 * (its template read throws), so purging the card must drop its rules too;
	 * otherwise an enabled orphan rule makes every cron pass log a failed spawn.
	 *
	 * @return int number of deleted rows (0 when the card anchored no rules)
	 * @throws Exception
	 */
	public function deleteByTemplateCardId(int $templateCardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('template_card_id', $qb->createNamedParameter($templateCardId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Every enabled rule due to fire now - the cron's work list. A rule is due
	 * when it is enabled, has a cached next fire time (`next_occurrence_at > 0`,
	 * so exhausted/never rules are excluded) and that time has passed. Hits the
	 * (enabled, next_occurrence_at) index.
	 *
	 * @return RecurRule[]
	 * @throws Exception
	 */
	public function findDueEnabled(int $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gt('next_occurrence_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('next_occurrence_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('next_occurrence_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}
}

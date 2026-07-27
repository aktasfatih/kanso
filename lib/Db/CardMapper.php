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
		'start_date',
		'done_at',
		'started_at',
		'archived',
		'owner',
		'created_at',
		'last_modified',
		'deleted_at',
		'parent_card_id',
		'priority',
		'estimate',
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

	/**
	 * Summary (no description) of the non-deleted card directly after the
	 * given sort key in a stack — the lower neighbour of a move target
	 * position. Null when the position is at the end of the stack.
	 *
	 * @throws Exception
	 */
	public function findNextInStack(int $stackId, string $sortKey): ?Card {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('sort_key', $qb->createNamedParameter($sortKey)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC')
			->setMaxResults(1);

		$cards = $this->findEntities($qb);
		return $cards[0] ?? null;
	}

	/**
	 * Summary (no description) of the first non-deleted card of a stack in
	 * display order, or null for an empty stack.
	 *
	 * @throws Exception
	 */
	public function findFirstInStack(int $stackId): ?Card {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC')
			->setMaxResults(1);

		$cards = $this->findEntities($qb);
		return $cards[0] ?? null;
	}

	/**
	 * Summary (no description) of the last non-deleted card of a stack in
	 * display order, or null for an empty stack — the append anchor for
	 * card creation and move-to-end.
	 *
	 * @throws Exception
	 */
	public function findLastInStack(int $stackId): ?Card {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'DESC')
			->setMaxResults(1);

		$cards = $this->findEntities($qb);
		return $cards[0] ?? null;
	}

	/**
	 * Cards (with description) matching a LIKE pattern in their title or
	 * description, restricted to the given readable boards and non-deleted.
	 * Portable case-insensitive LIKE (no per-dialect full-text) — the pattern is
	 * pre-escaped and wrapped by the caller. Title matches sort first, then most
	 * recent. $boardIds must be non-empty (the caller returns early otherwise).
	 *
	 * @param int[] $boardIds
	 * @return Card[]
	 * @throws Exception
	 */
	public function searchInBoards(array $boardIds, string $likePattern, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('title', $qb->createNamedParameter($likePattern)),
				$qb->expr()->iLike('description', $qb->createNamedParameter($likePattern)),
			))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Summaries (no description) of the SOFT-DELETED cards of a board — the
	 * trash listing, most-recently-deleted first.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findDeletedByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('deleted_at', 'DESC')
			->addOrderBy('id', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Summaries (no description) of the non-deleted children of a card, in
	 * display order (by stack, then sort key).
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findChildren(int $parentCardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('parent_card_id', $qb->createNamedParameter($parentCardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('stack_id', 'ASC')
			->addOrderBy('sort_key', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Whether the card has at least one non-deleted child — the one-level guard
	 * for {@see \OCA\Kanso\Service\CardService::setParent} (a card that is
	 * already a parent may not itself become a child).
	 *
	 * @throws Exception
	 */
	public function hasChildren(int $cardId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('parent_card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$has = $result->fetchOne() !== false;
		$result->closeCursor();

		return $has;
	}

	/**
	 * Per-parent child progress for every non-deleted card on a board that has
	 * children, as a fixed two-query self-scan — the board payload stays a
	 * constant number of queries. "done" counts children whose `done_at > 0`.
	 * Parents with no children are absent from the map (callers default to 0/0).
	 *
	 * @return array<int, array{total: int, done: int}> map of parentCardId => counts
	 * @throws Exception
	 */
	public function childProgressByBoard(int $boardId): array {
		$totals = $this->countChildrenByBoard($boardId, false);
		$done = $this->countChildrenByBoard($boardId, true);

		$map = [];
		foreach ($totals as $parentId => $count) {
			$map[$parentId] = ['total' => $count, 'done' => $done[$parentId] ?? 0];
		}
		return $map;
	}

	/**
	 * Child counts grouped by parent for a board, optionally restricted to done
	 * children (`done_at > 0`). Only non-deleted children with a parent are
	 * counted.
	 *
	 * @return array<int, int> map of parentCardId => count
	 * @throws Exception
	 */
	private function countChildrenByBoard(int $boardId, bool $doneOnly): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('parent_card_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('parent_card_id'))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('parent_card_id');

		if ($doneOnly) {
			$qb->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['parent_card_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Summaries (no description) of cards eligible for auto-archive under a
	 * rule's scope and condition, oldest done first, capped at $limit.
	 *
	 * All timestamp columns (`done_at`, `created_at`) are plain unix ints, so
	 * the age test is a direct integer comparison — no PARAM_DATE dance. A
	 * card qualifies when it is done (`done_at > 0`), not yet archived, not
	 * soft-deleted, in scope (board, and stack when $stackId is set), and:
	 *   - condition 0 (done-for): it has been done for at least the threshold
	 *     (`done_at <= cutoff`);
	 *   - condition 1 (done-and-age): it is done AND was created at least the
	 *     threshold ago (`created_at <= cutoff`).
	 * The query alone enforces "never touch not-done / archived / deleted",
	 * which is what makes the sweep idempotent.
	 *
	 * @param int $condition one of ArchiveRule::CONDITION_*
	 * @param int $cutoff now minus the rule's threshold (unix seconds)
	 * @return Card[]
	 * @throws Exception
	 */
	public function findEligibleForArchive(int $boardId, ?int $stackId, int $condition, int $cutoff, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('done_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);

		if ($stackId !== null) {
			$qb->andWhere($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)));
		}

		// The done_at > 0 guard above already covers the "done" half of both
		// conditions; here we add the age half against the right column.
		$column = $condition === ArchiveRule::CONDITION_DONE_AND_AGE ? 'created_at' : 'done_at';
		$qb->andWhere($qb->expr()->lte($column, $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * The current user's open, assigned cards across a set of boards — powers
	 * the cross-board "My tasks" panel. ACL is enforced by the caller passing
	 * only the boards the user can read (mirrors {@see CardReviewMapper::findByReviewerInBoards}).
	 * Excludes done, archived and deleted cards (a task list shows open work).
	 * Ordered undated-last, then by due date, then priority — so the soonest
	 * actionable work floats to the top. Capped at $limit rows.
	 *
	 * @param string[] $uids the assignee identities to match (a user's uid plus any group ids they belong to)
	 * @param int[] $boardIds the readable board set; an empty set yields no rows
	 * @return list<array<string, mixed>>
	 * @throws Exception
	 */
	public function findAssignedInBoards(array $uids, array $boardIds, int $limit = 200): array {
		if ($uids === [] || $boardIds === []) {
			return [];
		}

		// No DISTINCT needed: the type=user filter plus a per-(card,participant)
		// unique assignment means each card matches at most once — and DISTINCT
		// would collide with the CASE-based ORDER BY on Postgres anyway.
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id')
			->addSelect('c.board_id', 'c.title', 'c.duedate', 'c.priority', 'c.done_at', 'c.started_at', 'c.parent_card_id')
			->selectAlias('b.title', 'board_title')
			->selectAlias('s.title', 'stack_title')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'kanso_card_assignees', 'ca', $qb->expr()->eq('ca.card_id', 'c.id'))
			->innerJoin('c', 'kanso_boards', 'b', $qb->expr()->eq('c.board_id', 'b.id'))
			->leftJoin('c', 'kanso_stacks', 's', $qb->expr()->eq('c.stack_id', 's.id'))
			->where($qb->expr()->in('ca.participant', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('ca.type', $qb->createNamedParameter(CardAssignee::TYPE_USER, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('c.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('c.done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			// Undated cards last: a card with no due date sorts after any dated one.
			->addOrderBy($qb->createFunction('CASE WHEN c.duedate IS NULL THEN 1 ELSE 0 END'), 'ASC')
			->addOrderBy('c.duedate', 'ASC')
			->addOrderBy('c.priority', 'DESC')
			->addOrderBy('c.id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$due = $row['duedate'] !== null ? (new \DateTime((string)$row['duedate']))->format(\DateTimeInterface::ATOM) : null;
			$rows[] = [
				'id' => (int)$row['id'],
				'boardId' => (int)$row['board_id'],
				'boardTitle' => (string)$row['board_title'],
				'stackTitle' => $row['stack_title'] !== null ? (string)$row['stack_title'] : null,
				'title' => (string)$row['title'],
				'duedate' => $due,
				'priority' => (int)$row['priority'],
				'doneAt' => (int)$row['done_at'],
				'startedAt' => (int)$row['started_at'],
				'parentCardId' => ((int)($row['parent_card_id'] ?? 0)) ?: null,
			];
		}
		$result->closeCursor();

		return $rows;
	}
}

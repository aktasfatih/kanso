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
	 * excluded from board/stack listings - this is the charter's
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
		'all_day',
		'owner',
		'created_at',
		'last_modified',
		'deleted_at',
		'parent_card_id',
		'priority',
		'estimate',
		'board_seq',
		'due_reminder_sent',
		'day_before_reminder_sent',
		'due_reminder_day_before',
	];

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_cards', Card::class);
	}

	/**
	 * The next per-board human-id sequence number: MAX(board_seq) + 1 for the
	 * board, or 1 when the board has no numbered card yet. Deleted cards still
	 * count so a number is never reused after a card is trashed (the id stays
	 * stable and unambiguous). The (board_id, board_seq) unique index guards the
	 * actual assignment against a concurrent create deriving the same value -
	 * {@see \OCA\Kanso\Service\CardService::create} retries on that collision.
	 *
	 * @throws Exception
	 */
	public function nextBoardSeq(int $boardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('board_seq'))
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return ((int)$max) + 1;
	}

	/**
	 * Full row including the description - single-card detail fetch.
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
	 * The non-deleted card carrying a given per-board sequence number - the
	 * lookup behind a `PREFIX-<board_seq>` cross-reference (#3611). Resolves on
	 * the (board_id, board_seq) UNIQUE index, so it is a single-row point read.
	 * A summary shape (no description) is enough: callers only need the id + title
	 * to render the link. Trashed cards are excluded so a stale reference to a
	 * deleted card resolves to null (falls back to the raw text) rather than
	 * linking to a card the board no longer shows.
	 *
	 * @throws Exception
	 */
	public function findByBoardAndSeq(int $boardId, int $seq): ?Card {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('board_seq', $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$cards = $this->findEntities($qb);
		return $cards[0] ?? null;
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
	 * All non-deleted cards on a board WITH their descriptions, grouped by stack
	 * and in display order - the source for the public read-only share snapshot
	 * (#3531), where descriptions are part of the exposed board content. This
	 * mirrors {@see self::findSummariesByBoard()} but adds `description`; it is
	 * deliberately separate so the summary hot-path stays description-free.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findPublicByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(array_merge(self::SUMMARY_COLUMNS, ['description']))
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
	 * given sort key in a stack - the lower neighbour of a move target
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
	 * display order, or null for an empty stack - the append anchor for
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
	 * Portable case-insensitive LIKE (no per-dialect full-text) - the pattern is
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
	 * Summaries (no description) of the SOFT-DELETED cards of a board - the
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
	 * Whether the card has at least one non-deleted child - the one-level guard
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
	 * children, as a fixed two-query self-scan - the board payload stays a
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
	 * the age test is a direct integer comparison - no PARAM_DATE dance. A
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
	 * Cards whose due-date reminder threshold has been crossed and that still
	 * have at least one un-fired reminder - the source for the due-reminder cron
	 * ({@see \OCA\Kanso\Service\DueReminderService}). Excludes done, archived and
	 * deleted cards, and any card without a due date (`duedate IS NULL`).
	 *
	 * A card qualifies when EITHER:
	 *   - the at-due reminder is unsent (`due_reminder_sent = 0`) and the due
	 *     date is at or before $now; OR
	 *   - the day-before reminder is opted-in + unsent
	 *     (`due_reminder_day_before` true, `day_before_reminder_sent = 0`) and
	 *     the due date is at or before $now + 86400 (i.e. duedate - 86400 <= now).
	 *
	 * The precise per-marker decision (which reminders to actually send) is made
	 * in PHP by the service against the same $now; this query is just the bounded
	 * candidate set. Ordered by duedate ASC (soonest first), capped at $limit.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findDueForReminder(int $now, int $limit): array {
		$nowDt = new \DateTime('@' . $now);
		$dayAheadDt = new \DateTime('@' . ($now + 86400));

		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('duedate'))
			->andWhere($qb->expr()->eq('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				// At-due reminder still owed.
				$qb->expr()->andX(
					$qb->expr()->eq('due_reminder_sent', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
					$qb->expr()->lte('duedate', $qb->createNamedParameter($nowDt, 'datetime')),
				),
				// Day-before reminder opted-in and still owed.
				$qb->expr()->andX(
					$qb->expr()->eq('due_reminder_day_before', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
					$qb->expr()->eq('day_before_reminder_sent', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
					$qb->expr()->lte('duedate', $qb->createNamedParameter($dayAheadDt, 'datetime')),
				),
			))
			->orderBy('duedate', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * The current user's open, assigned cards across a set of boards - powers
	 * the cross-board "My tasks" panel. ACL is enforced by the caller passing
	 * only the boards the user can read (mirrors {@see CardReviewMapper::findByReviewerInBoards}).
	 * Excludes done, archived and deleted cards (a task list shows open work).
	 * Ordered undated-last, then by due date, then priority - so the soonest
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
		// unique assignment means each card matches at most once - and DISTINCT
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

	/**
	 * Open (non-deleted) card counts grouped by stack for a board - the
	 * "cards per column" board-stats aggregate. One grouped query; stacks with
	 * no open cards are simply absent from the list (the frontend defaults 0).
	 *
	 * @return list<array{stackId: int, count: int}>
	 * @throws Exception
	 */
	public function countByStack(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('stack_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('stack_id');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['stackId' => (int)$row['stack_id'], 'count' => (int)$row['cnt']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Open (non-deleted) card counts grouped by priority for a board.
	 *
	 * @return list<array{priority: int, count: int}>
	 * @throws Exception
	 */
	public function countByPriority(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('priority')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('priority');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['priority' => (int)$row['priority'], 'count' => (int)$row['cnt']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Number of open cards on a board that have been sitting since before
	 * $cutoff - the aging bucket. "Open" here means not done (`done_at = 0`),
	 * not archived, not soft-deleted; "old" means created at or before the
	 * cutoff. `created_at` is a plain unix int so this is a direct comparison
	 * (no per-dialect date SQL). There is no last-moved column, so age is
	 * measured from creation.
	 *
	 * @throws Exception
	 */
	public function agingCount(int $boardId, int $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Number of open cards on a board whose due date is already in the past -
	 * the overdue count. "Open" means not done, not archived, not soft-deleted.
	 * `duedate` is a DATETIME column, so the comparison binds a DATETIME
	 * parameter (dialect-clean, same type QBMapper serializes the column as)
	 * and adds a NOT NULL guard (an undated card is never overdue).
	 *
	 * @throws Exception
	 */
	public function overdueCount(int $boardId, \DateTime $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('duedate'))
			->andWhere($qb->expr()->lt('duedate', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Raw `done_at` timestamps of non-deleted cards completed in the window
	 * [$sinceTs, $untilTs] - the throughput timeline source. Bucketing into
	 * per-day counts is done in PHP (dialect-safe: no per-dialect date SQL);
	 * only cards actually done (`done_at > 0`) inside the window are returned.
	 *
	 * @return int[] the done_at unix timestamps (unordered)
	 * @throws Exception
	 */
	public function doneTimeline(int $boardId, int $sinceTs, int $untilTs): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('done_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('done_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('done_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$stamps = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $stamps;
	}

	/**
	 * Per-card completion facts for every non-deleted card done in the window
	 * [$sinceTs, $untilTs] - the source for the velocity and lead/cycle-time
	 * metrics. Each row is (created_at, done_at, estimate) so the caller can, in
	 * one read, bucket completions by week (velocity), sum numeric estimates by
	 * week (points velocity), and derive create→done durations (cycle time).
	 *
	 * Runs ALONGSIDE {@see self::doneTimeline()} (which returns done_at only) so
	 * the throughput timeline stays a single-column read; this richer query is
	 * issued only where the extra columns are needed. All aggregation (weekly
	 * buckets, median/average, estimate sums) happens in PHP so the SQL carries
	 * no per-dialect date, percentile, or CAST logic. `estimate` is the raw
	 * nullable scale token; the caller applies the numeric guard. Only cards
	 * actually done (`done_at > 0`) inside the window are returned.
	 *
	 * @return list<array{createdAt: int, doneAt: int, estimate: ?string}> unordered
	 * @throws Exception
	 */
	public function doneCycleTimes(int $boardId, int $sinceTs, int $untilTs): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at', 'done_at', 'estimate')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('done_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('done_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'createdAt' => (int)$row['created_at'],
				'doneAt' => (int)$row['done_at'],
				'estimate' => $row['estimate'] !== null ? (string)$row['estimate'] : null,
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Raw `created_at` timestamps of non-deleted cards created in the window
	 * [$sinceTs, $untilTs] - the "created" timeline source. Bucketed into
	 * per-day counts in PHP, same dialect-safe approach as
	 * {@see self::doneTimeline()}.
	 *
	 * @return int[] the created_at unix timestamps (unordered)
	 * @throws Exception
	 */
	public function createdTimeline(int $boardId, int $sinceTs, int $untilTs): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$stamps = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $stamps;
	}

	/**
	 * The raw estimate token of every open (non-deleted) card on a board that
	 * carries one, paired with its stack id - the source for the
	 * per-stack estimate sum. Summing is done in PHP after casting the numeric
	 * tokens (avoids CAST portability issues; only meaningful for numeric
	 * scales, which the caller has already checked). Cards with no estimate are
	 * excluded.
	 *
	 * @return list<array{stackId: int, estimate: string}>
	 * @throws Exception
	 */
	public function estimateByStack(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('stack_id', 'estimate')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('estimate'));

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['stackId' => (int)$row['stack_id'], 'estimate' => (string)$row['estimate']];
		}
		$result->closeCursor();

		return $rows;
	}

	// ── Board-id-set variants (boards-list per-board signal) ──────────────────
	//
	// These mirror the single-board aggregates above but group by `board_id` over
	// an explicit board id set (`board_id IN (:ids)`), so the boards LIST endpoint
	// can attach per-board signal in a FIXED number of queries regardless of how
	// many boards the user has - never one-query-per-board (the charter's
	// summary-payload / no-N+1 bet). The caller (BoardService::findAllWithStats)
	// supplies ONLY the board ids it has already ACL-resolved to the viewer's
	// readable set (BoardMapper::findAllForUser), so a board the viewer cannot
	// READ is simply never in the set and can never contribute a count - there is
	// no accidental full-table scan. An empty set short-circuits (never emit
	// `IN ()`, which is invalid SQL).

	/**
	 * Non-deleted card counts grouped by board over an explicit board id set - the
	 * "cards remaining" signal for the boards list. Mirrors the archived-consistency
	 * of the board-stats distribution aggregates ({@see self::countByStack()} /
	 * {@see self::countByPriority()}): ARCHIVED cards are EXCLUDED, so the count is
	 * the board's open (non-archived, non-deleted) card total - the actionable
	 * "cards remaining" figure, not a historical grand total. Boards with no such
	 * cards are absent from the map (callers default to 0).
	 *
	 * @param int[] $boardIds the viewer's readable board ids (empty → [])
	 * @return array<int, int> map of boardId => open card count
	 * @throws Exception
	 */
	public function countByBoards(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('board_id');

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['board_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Done-vs-total card counts grouped by board over an explicit board id set -
	 * the source for the boards-list progress % (done ratio). Both totals cover the
	 * same open scope as {@see self::countByBoards()} (non-deleted, non-archived);
	 * "done" is a card with `done_at > 0`. Two fixed grouped queries (total, done),
	 * so the list stays a constant query count. Boards with no open cards are absent
	 * from the map (callers default to 0/0 ⇒ 0 %).
	 *
	 * @param int[] $boardIds the viewer's readable board ids (empty → [])
	 * @return array<int, array{total: int, done: int}> map of boardId => counts
	 * @throws Exception
	 */
	public function doneRatioByBoards(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$totals = $this->countByBoards($boardIds);
		$done = $this->countDoneByBoards($boardIds);

		$map = [];
		foreach ($totals as $boardId => $count) {
			$map[$boardId] = ['total' => $count, 'done' => $done[$boardId] ?? 0];
		}
		return $map;
	}

	/**
	 * Done (non-deleted, non-archived, `done_at > 0`) card counts grouped by board
	 * over an explicit board id set - the "done" half of {@see self::doneRatioByBoards()}.
	 * Assumes a non-empty set (the caller guards).
	 *
	 * @param int[] $boardIds
	 * @return array<int, int> map of boardId => done card count
	 * @throws Exception
	 */
	private function countDoneByBoards(array $boardIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('board_id');

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['board_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Overdue open-card counts grouped by board over an explicit board id set - the
	 * boards-list overdue signal, the board-set twin of {@see self::overdueCount()}.
	 * "Overdue" is the same definition: not done, not archived, not soft-deleted,
	 * and a `duedate` in the past. `duedate` is a DATETIME column so the comparison
	 * binds a DATETIME parameter (dialect-clean) with a NOT NULL guard. Boards with
	 * no overdue cards are absent from the map (callers default to 0).
	 *
	 * @param int[] $boardIds the viewer's readable board ids (empty → [])
	 * @return array<int, int> map of boardId => overdue card count
	 * @throws Exception
	 */
	public function overdueCountByBoards(array $boardIds, \DateTime $now): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('duedate'))
			->andWhere($qb->expr()->lt('duedate', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->groupBy('board_id');

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['board_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $map;
	}

	// ── Card-id-set variants (project analytics) ──────────────────────────────
	//
	// These mirror the board-scoped aggregates above but key on an explicit card
	// id set (`c.id IN (:ids)`) instead of `board_id = :id`, so StatsService can
	// aggregate over a project's ACL-resolved cross-board card set (#3568). The
	// caller supplies ONLY card ids it has already ACL-filtered to the viewer's
	// readable boards (see {@see \OCA\Kanso\Db\ProjectCardMapper::findCardsInProjectAndBoards}),
	// so there is no board scope here and no cross-board leak: a card the viewer
	// cannot read is simply never in the set. An empty set short-circuits (never
	// emit `IN ()`, which is invalid SQL and would otherwise match nothing).
	// board-specific aggregates (countByStack, estimateByStack) are deliberately
	// NOT duplicated - a per-stack roll-up is meaningless across boards.

	/**
	 * Open (non-deleted, non-archived) card counts grouped by priority over an
	 * explicit card id set - the project-analytics twin of {@see self::countByPriority()}.
	 *
	 * @param int[] $cardIds the viewer's ACL-resolved project card ids (empty → [])
	 * @return list<array{priority: int, count: int}>
	 * @throws Exception
	 */
	public function countByPriorityForCards(array $cardIds): array {
		if ($cardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('priority')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('priority');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['priority' => (int)$row['priority'], 'count' => (int)$row['cnt']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Count of aging open cards over an explicit card id set - the twin of
	 * {@see self::agingCount()}.
	 *
	 * @param int[] $cardIds the viewer's ACL-resolved project card ids (empty → 0)
	 * @throws Exception
	 */
	public function agingCountForCards(array $cardIds, int $cutoff): int {
		if ($cardIds === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Count of overdue open cards over an explicit card id set - the twin of
	 * {@see self::overdueCount()}.
	 *
	 * @param int[] $cardIds the viewer's ACL-resolved project card ids (empty → 0)
	 * @throws Exception
	 */
	public function overdueCountForCards(array $cardIds, \DateTime $now): int {
		if ($cardIds === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('duedate'))
			->andWhere($qb->expr()->lt('duedate', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * `done_at` timestamps of cards done in the window over an explicit card id
	 * set - the twin of {@see self::doneTimeline()} (throughput source).
	 *
	 * @param int[] $cardIds the viewer's ACL-resolved project card ids (empty → [])
	 * @return int[] the done_at unix timestamps (unordered)
	 * @throws Exception
	 */
	public function doneTimelineForCards(array $cardIds, int $sinceTs, int $untilTs): array {
		if ($cardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('done_at')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('done_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('done_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$stamps = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $stamps;
	}

	/**
	 * `created_at` timestamps of cards created in the window over an explicit
	 * card id set - the twin of {@see self::createdTimeline()}.
	 *
	 * @param int[] $cardIds the viewer's ACL-resolved project card ids (empty → [])
	 * @return int[] the created_at unix timestamps (unordered)
	 * @throws Exception
	 */
	public function createdTimelineForCards(array $cardIds, int $sinceTs, int $untilTs): array {
		if ($cardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$stamps = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $stamps;
	}

	/**
	 * Per-card completion facts (created_at, done_at, estimate) for cards done in
	 * the window over an explicit card id set - the velocity + cycle-time source,
	 * the twin of {@see self::doneCycleTimes()}. The caller applies its own
	 * estimate guard; project analytics omits points entirely (mixed scales).
	 *
	 * @param int[] $cardIds the viewer's ACL-resolved project card ids (empty → [])
	 * @return list<array{createdAt: int, doneAt: int, estimate: ?string}> unordered
	 * @throws Exception
	 */
	public function doneCycleTimesForCards(array $cardIds, int $sinceTs, int $untilTs): array {
		if ($cardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at', 'done_at', 'estimate')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('done_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('done_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'createdAt' => (int)$row['created_at'],
				'doneAt' => (int)$row['done_at'],
				'estimate' => $row['estimate'] !== null ? (string)$row['estimate'] : null,
			];
		}
		$result->closeCursor();

		return $rows;
	}
}

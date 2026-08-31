<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_cards`.
 *
 * Card-visibility (#3743): every method that feeds a VIEWER-FACING payload
 * takes the viewer - a {@see ViewerContext} (board-scoped) or a
 * (uid, rolesByBoard) pair (cross-board) - and applies
 * {@see CardVisibilityScope} in SQL, so a hidden card never leaves the
 * database. Methods without a viewer are internal mechanics (sort-key
 * neighbours, rebalance, cron candidate sets, parent auto-complete) whose
 * results never reach a response directly.
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
		'cover_color',
		'type',
		'is_template',
		// Card visibility (#3741/#3743): in the summary so the tile badge and
		// the picker render without a detail fetch. `creator_role` is NOT
		// selected - the scope filters on it, the payload never carries it.
		'visibility',
	];

	public function __construct(
		IDBConnection $db,
		private CardVisibilityScope $visibilityScope,
	) {
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
	 * A reference to a card the viewer cannot SEE resolves to null too (#3743) -
	 * indistinguishable from a reference to a card that never existed.
	 *
	 * @throws Exception
	 */
	public function findByBoardAndSeq(int $boardId, int $seq, ViewerContext $viewer): ?Card {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('board_seq', $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

		$cards = $this->findEntities($qb);
		return $cards[0] ?? null;
	}

	/**
	 * Summaries (no description) of all non-deleted, NON-TEMPLATE cards on a
	 * board, grouped by stack and in display order. Template cards (#3409) are
	 * EXCLUDED here at the query level so a per-board template never enters the
	 * board payload or the virtualized list - templates are blueprints, not live
	 * work. {@see self::findTemplatesByBoard()} lists them separately for the picker.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findSummariesByBoard(int $boardId, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('stack_id', 'ASC')
			->addOrderBy('sort_key', 'ASC');
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

		return $this->findEntities($qb);
	}

	/**
	 * Summaries (no description) of the given non-deleted, NON-TEMPLATE cards of a
	 * board - the delta-sync counterpart of {@see self::findSummariesByBoard()},
	 * restricted to an explicit id set (the cards a `?since=` window touched). Same
	 * summary shape and same live-card filters (not deleted, not a template) so a
	 * card that was deleted or turned into a template between the client's cursor
	 * and now is simply absent from the result - the controller then emits it as a
	 * `cards.remove`. Board-scoped like every board query. An empty id set
	 * short-circuits (never emit `IN ()`, which is invalid SQL).
	 *
	 * @param int[] $ids
	 * @return Card[]
	 * @throws Exception
	 */
	public function findSummariesByIds(int $boardId, array $ids, ViewerContext $viewer): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
		// Visibility (#3743): a hidden card simply drops out of the delta
		// upsert set - the changes() endpoint then emits it as a `remove`,
		// which is exactly the right client behavior for a card the viewer
		// may (no longer) see. Its change rows never carry a title.
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

		return $this->findEntities($qb);
	}

	/**
	 * Summaries (no description) of the non-deleted TEMPLATE cards of a board -
	 * the per-board template picker source (#3409). The exact complement of
	 * {@see self::findSummariesByBoard()}'s template exclusion. Ordered by title
	 * so the picker lists blueprints alphabetically; templates are per-board only
	 * (no cross-board gallery), so this is board-scoped like every board query.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findTemplatesByBoard(int $boardId, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('title', 'ASC')
			->addOrderBy('id', 'ASC');
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
			// Templates (#3409) are excluded from the public snapshot for the same
			// reason as the live board render: they are blueprints, not board content.
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('stack_id', 'ASC')
			->addOrderBy('sort_key', 'ASC');
		// The share viewer is ANONYMOUS: 'public' cards only, no role branch
		// ever - regardless of any ACL on the board (#3743).
		$this->visibilityScope->applyPublicOnly($qb, '');

		return $this->findEntities($qb);
	}

	/**
	 * Summaries (no description) of the non-deleted, non-archived, non-template
	 * cards on a board that HAVE a due date - the source for the read-only ICS /
	 * iCal due-date feed (#3541). One board-scoped query (no N+1); a card without
	 * a `duedate` is filtered out in SQL. Ordered by due date so the feed reads
	 * chronologically. Board-scoped like every board query, so the feed can only
	 * ever expose one board's due cards.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findWithDuedateByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('duedate'))
			->orderBy('duedate', 'ASC')
			->addOrderBy('id', 'ASC');
		// The feed token authenticates a BOARD, not a person: the reader is
		// anonymous, so only 'public' cards may ever reach the calendar (#3743).
		$this->visibilityScope->applyPublicOnly($qb, '');

		return $this->findEntities($qb);
	}

	/**
	 * Summaries (no description) of the non-deleted, non-archived, non-template
	 * cards on a board that HAVE a due date, scoped to what a SPECIFIC VIEWER may
	 * see - the source for the read-only CalDAV VTODO calendar (#3534 / issue
	 * #49). The authenticated counterpart of {@see self::findWithDuedateByBoard}:
	 * that one is anonymous (public-only) for the token feed, this one honours the
	 * viewer's card-visibility so a board member syncing over CalDAV sees exactly
	 * the due cards they see on the board. Board-scoped like every board query.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findDuedateSummariesByBoard(int $boardId, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('duedate'))
			->orderBy('duedate', 'ASC')
			->addOrderBy('id', 'ASC');
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

		return $this->findEntities($qb);
	}

	/**
	 * FULL rows (description included) of every non-deleted card on a board
	 * that the VIEWER can see - templates and archived cards included, exactly
	 * the set the board export/duplicate may serialize (#3743). One query
	 * (replaces the old per-stack walk + per-card detail refetch); the caller
	 * groups by stack. Ordered by stack, then display order, so per-stack
	 * card order matches the old export byte-for-byte.
	 *
	 * $viewer = null is the SYSTEM scope (unfiltered) - reserved for the
	 * admin backup ({@see \OCA\Kanso\Service\ExportService::export()}), whose
	 * output never reaches an HTTP response. No default: callers choose.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findExportableByBoard(int $boardId, ?ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('stack_id', 'ASC')
			->addOrderBy('sort_key', 'ASC');
		if ($viewer !== null) {
			$this->visibilityScope->applyForViewer($qb, '', $viewer);
		}

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
	 * Live cards of a stack in display order, taking a row-level write lock
	 * (SELECT ... FOR UPDATE) on each returned row - the read half of a
	 * {@see \OCA\Kanso\Service\CardService::rebalanceStack()} inside a
	 * transaction. Locking the stack's rows serialises the rebalance against a
	 * concurrent move deriving a key from the same neighbours, without a new
	 * global lock: it matches the app's READ-COMMITTED move posture, just
	 * pessimistically for the (rare) rebalance path.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findByStackForUpdate(int $stackId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC')
			->forUpdate();

		return $this->findEntities($qb);
	}

	/**
	 * Rewrites a single card's `sort_key` in place - the write half of a
	 * rebalance. A targeted UPDATE (not a full-entity update) so it touches
	 * only the reordering column and never clobbers fields absent from the
	 * summary payload.
	 *
	 * @throws Exception
	 */
	public function updateSortKeyById(int $cardId, string $sortKey): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('sort_key', $qb->createNamedParameter($sortKey))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Compare-and-set on `description_revision` - the atomic half of the
	 * description's optimistic concurrency (#9848). Advances the counter to
	 * $baseRevision + 1 ONLY while the stored value is still $baseRevision, in
	 * ONE statement, so two writers that started from the same version cannot
	 * both pass: the loser's WHERE no longer matches. Called inside the update
	 * transaction, so the row lock it takes also serialises the description
	 * write that follows it.
	 *
	 * Plain integer comparison, so it behaves identically on SQLite, MySQL and
	 * PostgreSQL - a text digest could not (SQLite has no built-in sha1/md5,
	 * and comparing the raw text collates case-insensitively on MySQL).
	 *
	 * Affected rows are 1 = claimed, 0 = somebody else got there first - and that
	 * is unambiguous BECAUSE the counter strictly increments: the row always
	 * changes when the WHERE matches, so a driver that reports changed-rows
	 * (MySQL without CLIENT_FOUND_ROWS) can never report 0 for a match. Do not
	 * "optimise" this into a no-op-when-equal write.
	 *
	 * A targeted UPDATE (not a full-entity write), same shape as
	 * {@see self::updateSortKeyById()}; the description text itself is written
	 * by the ordinary entity update in the same transaction.
	 *
	 * @return int the number of affected rows
	 * @throws Exception
	 */
	public function claimDescriptionRevision(int $cardId, int $baseRevision): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('description_revision', $qb->createNamedParameter($baseRevision + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('description_revision', $qb->createNamedParameter($baseRevision, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Unconditionally advances `description_revision` by one and returns the new
	 * value - what an UNGUARDED description write (an API client, the MCP server)
	 * uses to keep the counter moving without claiming anything.
	 *
	 * The increment is computed IN SQL (`= description_revision + 1`), never from
	 * a value read earlier in PHP: a read-modify-write would be evaluated against
	 * a row another writer may already have moved, which could stall the counter
	 * (letting a guarded editor's next save clobber this text unnoticed) or even
	 * walk it BACKWARDS (making a correctly-seeded editor conflict). Everything
	 * else here assumes the counter only ever climbs.
	 *
	 * The read-back runs in the caller's transaction, after the UPDATE has taken
	 * the row lock, so it returns exactly the value this write will commit.
	 *
	 * @return int the card's new description revision
	 * @throws Exception
	 */
	public function bumpDescriptionRevision(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('description_revision', $qb->createFunction($qb->getColumnName('description_revision') . ' + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$read = $this->db->getQueryBuilder();
		$read->select('description_revision')
			->from($this->getTableName())
			->where($read->expr()->eq('id', $read->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));
		$result = $read->executeQuery();
		$revision = $result->fetchOne();
		$result->closeCursor();

		return (int)$revision;
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
	 * Visibility (#3743): cross-board scope over the viewer's per-board roles,
	 * applied in SQL - a hidden card can never match, not even by title.
	 *
	 * @param int[] $boardIds
	 * @param array<int, string> $rolesByBoard the viewer's role per board id
	 *                                         ({@see \OCA\Kanso\Access\BoardAccess::rolesFor()})
	 * @return Card[]
	 * @throws Exception
	 */
	public function searchInBoards(array $boardIds, string $likePattern, int $limit, string $uid, array $rolesByBoard): array {
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
		$this->visibilityScope->apply($qb, '', $uid, null, $rolesByBoard);

		return $this->findEntities($qb);
	}

	/**
	 * Summaries (no description) of the SOFT-DELETED cards of a board - the
	 * trash listing, most-recently-deleted first.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findDeletedByBoard(int $boardId, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('deleted_at', 'DESC')
			->addOrderBy('id', 'DESC');
		// A hidden card stays hidden in the trash too (#3743) - deleting a
		// private/internal card must not surface it to the other side.
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	 * The viewer-facing twin of {@see self::findChildren()} for the card
	 * detail payload (#3743): hidden children are dropped in SQL. The unscoped
	 * variant stays for INTERNAL logic (parent auto-complete, delete-detach),
	 * where correctness must count every child - hidden ones included.
	 *
	 * @return Card[]
	 * @throws Exception
	 */
	public function findVisibleChildren(int $parentCardId, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(self::SUMMARY_COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('parent_card_id', $qb->createNamedParameter($parentCardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('stack_id', 'ASC')
			->addOrderBy('sort_key', 'ASC');
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	 * Visibility (#3743): only children the VIEWER can see are counted, so a
	 * private child can never betray its existence through a parent's child
	 * count (counts are part of the leak surface).
	 *
	 * @return array<int, array{total: int, done: int}> map of parentCardId => counts
	 * @throws Exception
	 */
	public function childProgressByBoard(int $boardId, ViewerContext $viewer): array {
		$totals = $this->countChildrenByBoard($boardId, false, $viewer);
		$done = $this->countChildrenByBoard($boardId, true, $viewer);

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
	private function countChildrenByBoard(int $boardId, bool $doneOnly, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('parent_card_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('parent_card_id'))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('parent_card_id');
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	 * Visibility (#3743): the viewer's per-board roles scope the query - being
	 * ASSIGNED to a card grants no visibility beyond the rule (an external
	 * assigned to a provider-internal card must not see it here either).
	 *
	 * @param string[] $uids the assignee identities to match (a user's uid plus any group ids they belong to)
	 * @param int[] $boardIds the readable board set; an empty set yields no rows
	 * @param array<int, string> $rolesByBoard the viewer's role per board id
	 * @return list<array<string, mixed>>
	 * @throws Exception
	 */
	public function findAssignedInBoards(array $uids, array $boardIds, string $viewerUid, array $rolesByBoard, int $limit = 200): array {
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
		$this->visibilityScope->apply($qb, 'c', $viewerUid, null, $rolesByBoard);

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
	 * Open (non-deleted, non-template) card counts grouped by stack for a board -
	 * the "cards per column" board-stats aggregate. One grouped query; stacks with
	 * no open cards are simply absent from the list (the frontend defaults 0).
	 * Template cards (#3409/#3626) are excluded like everywhere they would inflate
	 * a board figure - they are blueprints, not live work.
	 *
	 * @return list<array{stackId: int, count: int}>
	 * @throws Exception
	 */
	public function countByStack(int $boardId, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('stack_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('stack_id');
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['stackId' => (int)$row['stack_id'], 'count' => (int)$row['cnt']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Open (non-deleted, non-template) card counts grouped by priority for a board.
	 * Template cards (#3409/#3626) are excluded so a board's priority distribution
	 * reflects live work only.
	 *
	 * @return list<array{priority: int, count: int}>
	 * @throws Exception
	 */
	public function countByPriority(int $boardId, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('priority')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('priority');
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	 * measured from creation. Template cards (#3409/#3626) never count as aging.
	 *
	 * @throws Exception
	 */
	public function agingCount(int $boardId, int $cutoff, ViewerContext $viewer): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	 * and adds a NOT NULL guard (an undated card is never overdue). Template cards
	 * (#3409/#3626) are excluded - a blueprint's due date is not real work overdue.
	 *
	 * @throws Exception
	 */
	public function overdueCount(int $boardId, \DateTime $now, ViewerContext $viewer): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('duedate'))
			->andWhere($qb->expr()->lt('duedate', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	 * Template cards (#3409/#3626) are excluded so they never inflate throughput.
	 *
	 * @return int[] the done_at unix timestamps (unordered)
	 * @throws Exception
	 */
	public function doneTimeline(int $boardId, int $sinceTs, int $untilTs, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('done_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('done_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('done_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	public function doneCycleTimes(int $boardId, int $sinceTs, int $untilTs, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at', 'done_at', 'estimate')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('done_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('done_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	public function createdTimeline(int $boardId, int $sinceTs, int $untilTs, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('created_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($untilTs, IQueryBuilder::PARAM_INT)));
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

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
	public function estimateByStack(int $boardId, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('stack_id', 'estimate')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('estimate'));
		$this->visibilityScope->applyForViewer($qb, '', $viewer);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['stackId' => (int)$row['stack_id'], 'estimate' => (string)$row['estimate']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Every non-deleted card on a board that carries an estimate, as (id, token)
	 * pairs. Used by a board scale change to find estimates that no longer fit the
	 * new scale so they can be cleared {@see self::clearEstimatesByIds()}. NOT
	 * viewer-scoped and NOT archived/template-filtered on purpose: a scale change
	 * is a board-wide data-integrity fix that must reach every stranded token, not
	 * only the ones the acting manager can currently see.
	 *
	 * @return list<array{id: int, estimate: string}>
	 * @throws Exception
	 */
	public function findEstimatedCards(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'estimate')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('estimate'));

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['id' => (int)$row['id'], 'estimate' => (string)$row['estimate']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Clears (sets NULL) the estimate of the given cards in one UPDATE and bumps
	 * their last_modified. No-op for an empty id list. The caller records a
	 * per-card change row for delta-sync/realtime.
	 *
	 * @param list<int> $cardIds
	 * @throws Exception
	 */
	public function clearEstimatesByIds(array $cardIds): void {
		if ($cardIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('estimate', $qb->createNamedParameter(null))
			->set('last_modified', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->in('id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
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
	 * cards are absent from the map (callers default to 0). Template cards
	 * (#3409/#3626) are excluded so a per-board template never inflates the signal.
	 *
	 * @param int[] $boardIds the viewer's readable board ids (empty → [])
	 * @param array<int, string> $rolesByBoard the viewer's role per board id
	 * @return array<int, int> map of boardId => open card count
	 * @throws Exception
	 */
	public function countByBoards(array $boardIds, string $uid, array $rolesByBoard): array {
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
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('board_id');
		$this->visibilityScope->apply($qb, '', $uid, null, $rolesByBoard);

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
	 * @param array<int, string> $rolesByBoard the viewer's role per board id
	 * @return array<int, array{total: int, done: int}> map of boardId => counts
	 * @throws Exception
	 */
	public function doneRatioByBoards(array $boardIds, string $uid, array $rolesByBoard): array {
		if ($boardIds === []) {
			return [];
		}

		$totals = $this->countByBoards($boardIds, $uid, $rolesByBoard);
		$done = $this->countDoneByBoards($boardIds, $uid, $rolesByBoard);

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
	private function countDoneByBoards(array $boardIds, string $uid, array $rolesByBoard): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gt('done_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('board_id');
		$this->visibilityScope->apply($qb, '', $uid, null, $rolesByBoard);

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
	 * @param array<int, string> $rolesByBoard the viewer's role per board id
	 * @return array<int, int> map of boardId => overdue card count
	 * @throws Exception
	 */
	public function overdueCountByBoards(array $boardIds, \DateTime $now, string $uid, array $rolesByBoard): array {
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
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('duedate'))
			->andWhere($qb->expr()->lt('duedate', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->groupBy('board_id');
		$this->visibilityScope->apply($qb, '', $uid, null, $rolesByBoard);

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
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
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
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
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
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
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
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
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
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
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
			->andWhere($qb->expr()->eq('is_template', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
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

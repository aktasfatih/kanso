<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for the boards-list per-board aggregates (#3571). The DB is
 * mocked so these verify the pure-PHP contract of each aggregate: the grouped
 * rows are assembled into a boardId => count map (correct across MULTIPLE boards),
 * an empty board-id set short-circuits without touching the DB, and only boards
 * present in the grouped rows contribute - a board outside the fed set (which, in
 * production, is the caller's ACL-resolved readable set) yields no entry.
 */
class CardMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private CardMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new CardMapper($this->db, new CardVisibilityScope());
	}

	/**
	 * A resolved board-scoped viewer for the visibility-scoped reads (#3743).
	 * The scope only appends extra WHERE branches, which the fluent QB mocks
	 * absorb - the rows fed back are what each assertion pins.
	 */
	private static function viewer(int $boardId = 7): ViewerContext {
		return ViewerContext::forMember('alice', $boardId, ViewerContext::ROLE_INTERNAL, true);
	}

	/**
	 * The cross-board role map matching {@see self::viewer()} for the
	 * board-set aggregates (uid + rolesByBoard instead of a ViewerContext).
	 *
	 * @param int[] $boardIds
	 * @return array<int, string>
	 */
	private static function roles(array $boardIds): array {
		return array_fill_keys($boardIds, ViewerContext::ROLE_INTERNAL);
	}

	/**
	 * A stand-in for the expression / function builders: any method call returns
	 * an empty string (the mappers only pass these into where()/select(), which
	 * are self-returning no-ops in these tests).
	 */
	private static function exprSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		};
	}

	/**
	 * A spying expression builder that records the column name of every comparison
	 * (eq/gt/gte/lt/lte/in/isNotNull) into a shared collector. Lets a test assert
	 * that an aggregate actually emits an `is_template` filter WHERE clause - which
	 * a plain row-feeding mock (rows come back regardless of the SQL) cannot verify.
	 * This is the regression guard for the template-exclusion pattern (#3626): drop
	 * the `is_template` filter from any aggregate and its assertion here goes red.
	 *
	 * @param array<int, string> $collector filled with the first argument (column) of each comparison
	 */
	private static function spyExpr(array &$collector): object {
		return new class($collector) {
			/** @param array<int, string> $seen */
			public function __construct(
				private array &$seen,
			) {
			}

			public function __call(string $name, array $args): string {
				if ($args !== [] && \is_string($args[0])) {
					$this->seen[] = $args[0];
				}
				return '';
			}
		};
	}

	/**
	 * Runs $call against a mapper whose query builder records every filtered column,
	 * and asserts `is_template` is among them - i.e. the aggregate excludes template
	 * cards. The fed rows are irrelevant to the assertion; the point is the WHERE.
	 *
	 * @param callable(CardMapper): mixed $call
	 */
	private function assertFiltersTemplates(callable $call): void {
		$columns = [];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'innerJoin', 'leftJoin', 'where',
			'andWhere', 'groupBy', 'orderBy', 'addOrderBy', 'setMaxResults',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$spy = self::spyExpr($columns);
		$qb->method('expr')->willReturn($spy);
		$qb->method('func')->willReturn(self::exprSink());
		$qb->method('createNamedParameter')->willReturn('?');
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('fetchOne')->willReturn(0);
		$result->method('fetchAll')->willReturn([]);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$mapper = new CardMapper($db, new CardVisibilityScope());

		$call($mapper);

		self::assertContains(
			'is_template',
			$columns,
			'aggregate must filter out template cards with an is_template WHERE clause'
		);
	}

	/**
	 * Builds a fluent query-builder mock that ignores every chained call and, on
	 * executeQuery(), returns a result iterating the given rows once.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function buildQb(array $rows): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'innerJoin', 'leftJoin', 'where',
			'andWhere', 'groupBy', 'orderBy', 'addOrderBy', 'setMaxResults',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		// expr()/func() are NOT mocked via createMock(): their OCP interfaces
		// reference Doctrine ExpressionBuilder symbols that are not autoloadable in
		// the unit env, so reflecting them fatals. A __call sink returns a harmless
		// value for every builder call the queries make.
		$sink = self::exprSink();
		$qb->method('expr')->willReturn($sink);
		$qb->method('func')->willReturn($sink);
		$qb->method('createNamedParameter')->willReturn('?');
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	/**
	 * Single-query aggregate: every getQueryBuilder() returns one QB over $rows.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function stubQuery(array $rows): void {
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb($rows));
	}

	/**
	 * Multi-query aggregate: successive getQueryBuilder() calls return QBs over
	 * the given row sets in order (used by the two-query doneRatioByBoards).
	 *
	 * @param list<list<array<string, mixed>>> $rowSets
	 */
	private function stubQueries(array $rowSets): void {
		$qbs = array_map(fn (array $rows): IQueryBuilder => $this->buildQb($rows), $rowSets);
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$qbs);
	}

	public function testCountByBoardsGroupsCountsAcrossBoards(): void {
		$this->stubQuery([
			['board_id' => 7, 'cnt' => 5],
			['board_id' => 9, 'cnt' => 2],
		]);

		self::assertSame([7 => 5, 9 => 2], $this->mapper->countByBoards([7, 9], 'alice', self::roles([7, 9])));
	}

	public function testCountByBoardsEmptySetShortCircuitsWithoutQuery(): void {
		// No getQueryBuilder() call is allowed for an empty set.
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->countByBoards([], 'alice', []));
	}

	public function testCountByBoardsOmitsBoardsWithNoOpenCards(): void {
		// Board 9 is in the requested (readable) set but produced no grouped row -
		// e.g. all its cards are archived/done-out-of-scope; it must contribute
		// nothing rather than a phantom 0-row.
		$this->stubQuery([['board_id' => 7, 'cnt' => 3]]);

		$map = $this->mapper->countByBoards([7, 9], 'alice', self::roles([7, 9]));
		self::assertSame([7 => 3], $map);
		self::assertArrayNotHasKey(9, $map);
	}

	public function testDoneRatioByBoardsPairsTotalsWithDonePerBoard(): void {
		// First query = totals, second query = done-only, both grouped by board.
		$this->stubQueries([
			[['board_id' => 7, 'cnt' => 5], ['board_id' => 9, 'cnt' => 4]],
			[['board_id' => 7, 'cnt' => 2]],
		]);

		self::assertSame([
			// board 7: 2 of 5 done; board 9: 0 done (absent from the done map).
			7 => ['total' => 5, 'done' => 2],
			9 => ['total' => 4, 'done' => 0],
		], $this->mapper->doneRatioByBoards([7, 9], 'alice', self::roles([7, 9])));
	}

	public function testDoneRatioByBoardsEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->doneRatioByBoards([], 'alice', []));
	}

	public function testOverdueCountByBoardsGroupsCountsAcrossBoards(): void {
		$this->stubQuery([
			['board_id' => 7, 'cnt' => 1],
			['board_id' => 9, 'cnt' => 4],
		]);

		self::assertSame(
			[7 => 1, 9 => 4],
			$this->mapper->overdueCountByBoards([7, 9], new \DateTime('@1000'), 'alice', self::roles([7, 9]))
		);
	}

	public function testOverdueCountByBoardsEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->overdueCountByBoards([], new \DateTime('@1000'), 'alice', []));
	}

	// ---- findByBoardAndSeq (PREFIX-<board_seq> point read, #3611) ----------

	public function testFindByBoardAndSeqReturnsTheMatchingCard(): void {
		// One row on the (board_id, board_seq) index → a hydrated summary Card.
		$this->stubQuery([[
			'id' => 42,
			'board_id' => 7,
			'stack_id' => 3,
			'title' => 'Referenced card',
			'sort_key' => 'aa',
			'board_seq' => 123,
			'deleted_at' => 0,
		]]);

		$card = $this->mapper->findByBoardAndSeq(7, 123, self::viewer());
		self::assertNotNull($card);
		self::assertSame(42, $card->getId());
		self::assertSame('Referenced card', $card->getTitle());
		self::assertSame(123, $card->getBoardSeq());
	}

	public function testFindByBoardAndSeqReturnsNullWhenNoRow(): void {
		// No card carries that sequence on the board (unknown/deleted) → null,
		// so a stale reference falls back to plain text.
		$this->stubQuery([]);

		self::assertNull($this->mapper->findByBoardAndSeq(7, 999, self::viewer()));
	}

	// ---- findSummariesByIds (delta-sync per-card re-serialize, #3675) ------

	public function testFindSummariesByIdsHydratesTheRequestedCards(): void {
		// The delta window touched card 42 → it comes back as a hydrated summary
		// (same shape as findSummariesByBoard, no description).
		$this->stubQuery([[
			'id' => 42,
			'board_id' => 7,
			'stack_id' => 3,
			'title' => 'Edited elsewhere',
			'sort_key' => 'aa',
			'deleted_at' => 0,
			'is_template' => false,
		]]);

		$cards = $this->mapper->findSummariesByIds(7, [42], self::viewer());
		self::assertCount(1, $cards);
		self::assertSame(42, $cards[0]->getId());
		self::assertSame('Edited elsewhere', $cards[0]->getTitle());
	}

	public function testFindSummariesByIdsEmptySetShortCircuitsWithoutQuery(): void {
		// An empty id set must never emit `IN ()` - no DB call at all.
		$this->db->expects(self::never())->method('getQueryBuilder');
		self::assertSame([], $this->mapper->findSummariesByIds(7, [], self::viewer()));
	}

	public function testFindSummariesByIdsExcludesTemplates(): void {
		// A card turned into a template between the cursor and now must not come
		// back as an upsert (the controller then emits it as a remove).
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->findSummariesByIds(7, [42], self::viewer()));
	}

	// ---- findTemplatesByBoard (per-board template picker, #3409) -----------

	public function testFindTemplatesByBoardHydratesTemplateRows(): void {
		// The picker query returns the board's template cards as hydrated summaries
		// carrying the is_template flag (the WHERE is_template = true filter itself
		// is exercised against a live DB by the e2e test).
		$this->stubQuery([
			[
				'id' => 42,
				'board_id' => 7,
				'stack_id' => 3,
				'title' => 'Bug report template',
				'sort_key' => 'aa',
				'deleted_at' => 0,
				'is_template' => true,
			],
		]);

		$templates = $this->mapper->findTemplatesByBoard(7, self::viewer());
		self::assertCount(1, $templates);
		self::assertSame(42, $templates[0]->getId());
		self::assertSame('Bug report template', $templates[0]->getTitle());
		self::assertTrue($templates[0]->getIsTemplate());
	}

	public function testFindTemplatesByBoardReturnsEmptyWhenNoTemplates(): void {
		$this->stubQuery([]);
		self::assertSame([], $this->mapper->findTemplatesByBoard(7, self::viewer()));
	}

	// ---- template exclusion from analytics aggregates (#3626) --------------
	//
	// Template cards (is_template = true) are ordinary rows with real priority,
	// created_at, done_at, estimate and can be overdue - so every board/project
	// stats aggregate must filter them out, exactly like archived/deleted cards,
	// or a board that defines templates reports inflated counts/velocity/throughput.
	// Each case asserts the aggregate emits the is_template WHERE clause; if a future
	// change drops it from the shared pattern, the matching assertion fails.

	public function testCountByStackExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->countByStack(7, self::viewer()));
	}

	public function testCountByPriorityExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->countByPriority(7, self::viewer()));
	}

	public function testAgingCountExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->agingCount(7, 1000, self::viewer()));
	}

	public function testOverdueCountExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->overdueCount(7, new \DateTime('@1000'), self::viewer()));
	}

	public function testDoneTimelineExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->doneTimeline(7, 0, 1000, self::viewer()));
	}

	public function testDoneCycleTimesExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->doneCycleTimes(7, 0, 1000, self::viewer()));
	}

	public function testCreatedTimelineExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->createdTimeline(7, 0, 1000, self::viewer()));
	}

	public function testEstimateByStackExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->estimateByStack(7, self::viewer()));
	}

	public function testCountByBoardsExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->countByBoards([7], 'alice', self::roles([7])));
	}

	public function testOverdueCountByBoardsExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->overdueCountByBoards([7], new \DateTime('@1000'), 'alice', self::roles([7])));
	}

	public function testCountByPriorityForCardsExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->countByPriorityForCards([1, 2, 3]));
	}

	public function testDoneTimelineForCardsExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->doneTimelineForCards([1, 2, 3], 0, 1000));
	}

	public function testDoneCycleTimesForCardsExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->doneCycleTimesForCards([1, 2, 3], 0, 1000));
	}

	/**
	 * End-to-end contract at the mapper's PHP layer: with the template row already
	 * filtered out by SQL (the DB returns only the N live rows), the per-stack count
	 * reflects the live cards only - here 3 live cards in stack 5, no phantom from a
	 * template. Complements the WHERE-clause assertions above (which prove the filter
	 * is emitted) by pinning the reported figure to the live set.
	 */
	// ---- the two "My tasks" assigned-card feeds (#3441 / #10061) -----------
	//
	// The open feed and the recently-done feed are two SEPARATE queries on
	// purpose. Relaxing `done_at = 0` on the open one would pull every task a
	// person has ever finished into the default page load - unbounded on a
	// long-lived board. These tests pin that separation, plus the two bounds
	// (recency window AND row cap) that keep the opt-in query cheap.

	/**
	 * A predicate-recording expression builder: every comparison is captured as
	 * {op, col, value}. Paired with a createNamedParameter that returns its
	 * argument verbatim, this makes the BOUND VALUE of each WHERE assertable -
	 * which is what a plain row-feeding mock (rows come back regardless of the
	 * SQL) can never show.
	 *
	 * @param list<array{op: string, col: mixed, value: mixed}> $collector
	 */
	private static function predicateSpy(array &$collector): object {
		return new class($collector) {
			/** @param list<array{op: string, col: mixed, value: mixed}> $seen */
			public function __construct(
				private array &$seen,
			) {
			}

			public function __call(string $name, array $args): string {
				$this->seen[] = [
					'op' => $name,
					'col' => $args[0] ?? null,
					'value' => $args[1] ?? null,
				];
				return '';
			}
		};
	}

	/**
	 * Runs $call against a mapper whose query builder records every predicate
	 * and every setMaxResults, and returns both.
	 *
	 * @param callable(CardMapper): mixed $call
	 * @return array{predicates: list<array{op: string, col: mixed, value: mixed}>, maxResults: list<int>}
	 */
	private function recordQuery(callable $call): array {
		$predicates = [];
		$maxResults = [];

		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'innerJoin', 'leftJoin',
			'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('setMaxResults')->willReturnCallback(function (?int $limit) use ($qb, &$maxResults): IQueryBuilder {
			$maxResults[] = (int)$limit;
			return $qb;
		});
		$qb->method('expr')->willReturn(self::predicateSpy($predicates));
		$qb->method('func')->willReturn(self::exprSink());
		// Identity, so the recorded predicates carry the real bound values.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$call(new CardMapper($db, new CardVisibilityScope()));

		return ['predicates' => $predicates, 'maxResults' => $maxResults];
	}

	/**
	 * @param list<array{op: string, col: mixed, value: mixed}> $predicates
	 * @return list<mixed> the bound values of every $op comparison on $column
	 */
	private static function boundValues(array $predicates, string $op, string $column): array {
		$values = [];
		foreach ($predicates as $predicate) {
			if ($predicate['op'] === $op && $predicate['col'] === $column) {
				$values[] = $predicate['value'];
			}
		}
		return $values;
	}

	public function testFindWithDuedateByBoardAppliesTheCallersRowCap(): void {
		// The ICS feed is the app's only ANONYMOUS card read and its caller
		// serialises every row it gets, so the cap has to reach the SQL - not just
		// sit in a constant. Drop the setMaxResults and this goes red.
		$recorded = $this->recordQuery(
			fn (CardMapper $m) => $m->findWithDuedateByBoard(7, 2000)
		);

		self::assertSame([2000], $recorded['maxResults'], 'the feed query must be bounded by the caller cap');
		self::assertSame([7], self::boundValues($recorded['predicates'], 'eq', 'board_id'), 'and stay board-scoped');
	}

	public function testFindAssignedInBoardsStillExcludesDoneCards(): void {
		// The DEFAULT My Tasks feed must keep its `done_at = 0` filter. Adding
		// the recently-done section must not have widened it - that widening is
		// exactly what the product asked NOT to happen, because it would put
		// every completed task on every page load.
		$recorded = $this->recordQuery(
			fn (CardMapper $m) => $m->findAssignedInBoards(['alice'], [7], 'alice', self::roles([7]))
		);

		self::assertSame([0], self::boundValues($recorded['predicates'], 'eq', 'c.done_at'));
		self::assertSame(
			[],
			self::boundValues($recorded['predicates'], 'gte', 'c.done_at'),
			'the open feed must not have grown a recency window - it is done-free by construction'
		);
	}

	public function testFindAssignedDoneSinceHydratesTheCompletedRows(): void {
		// Happy path: a completed assigned card comes back in the same summary
		// shape as the open feed (the client renders both lists), carrying the
		// completion timestamp the section sorts and labels by.
		$this->stubQuery([[
			'id' => 42,
			'board_id' => 7,
			'board_title' => 'Roadmap',
			'stack_title' => 'Done',
			'title' => 'Shipped it',
			'duedate' => null,
			'priority' => 1,
			'done_at' => 1_700_000_000,
			'started_at' => 0,
			'parent_card_id' => null,
		]]);

		$rows = $this->mapper->findAssignedDoneSinceInBoards(['alice'], [7], 'alice', self::roles([7]), 1_699_000_000, 50);

		self::assertCount(1, $rows);
		self::assertSame(42, $rows[0]['id']);
		self::assertSame('Shipped it', $rows[0]['title']);
		self::assertSame('Roadmap', $rows[0]['boardTitle']);
		self::assertSame(1_700_000_000, $rows[0]['doneAt']);
		self::assertNull($rows[0]['duedate']);
	}

	public function testFindAssignedDoneSinceIsBoundedByTheWindowAndTheRowCap(): void {
		// Both bounds, in the SQL. The recency cutoff is what makes the query
		// cheap - the row cap alone would still scan a lifetime of completed
		// work - so drop either and this goes red.
		$recorded = $this->recordQuery(
			fn (CardMapper $m) => $m->findAssignedDoneSinceInBoards(['alice'], [7], 'alice', self::roles([7]), 1_699_000_000, 50)
		);

		self::assertSame(
			[1_699_000_000],
			self::boundValues($recorded['predicates'], 'gte', 'c.done_at'),
			'the recency cutoff must reach the SQL, bound verbatim'
		);
		self::assertSame(
			[0],
			self::boundValues($recorded['predicates'], 'gt', 'c.done_at'),
			'only completed cards: done_at = 0 is the not-done sentinel'
		);
		self::assertSame([50], $recorded['maxResults'], 'the row cap must be applied');
	}

	public function testFindAssignedDoneSinceStillExcludesDeletedAndArchivedCards(): void {
		// Completed is not a licence to surface trashed or archived work.
		$recorded = $this->recordQuery(
			fn (CardMapper $m) => $m->findAssignedDoneSinceInBoards(['alice'], [7], 'alice', self::roles([7]), 1_699_000_000, 50)
		);

		self::assertSame([0], self::boundValues($recorded['predicates'], 'eq', 'c.deleted_at'));
		self::assertSame([false], self::boundValues($recorded['predicates'], 'eq', 'c.archived'));
	}

	public function testFindAssignedDoneSinceScopesToTheGivenBoardSet(): void {
		// The ACL boundary at the SQL layer: the caller's readable board set is
		// an IN filter, so a completed card on any other board cannot match.
		$recorded = $this->recordQuery(
			fn (CardMapper $m) => $m->findAssignedDoneSinceInBoards(['alice'], [7, 9], 'alice', self::roles([7, 9]), 1_699_000_000, 50)
		);

		// Several `board_id IN (…)` bindings are emitted - the feed's own filter
		// plus the visibility scope's per-side internal branch. EVERY one of them
		// must stay inside the readable set the caller passed.
		$boardFilters = self::boundValues($recorded['predicates'], 'in', 'c.board_id');
		self::assertNotEmpty($boardFilters, 'the readable board set must be an IN filter');
		foreach ($boardFilters as $bound) {
			self::assertEmpty(array_diff((array)$bound, [7, 9]), 'no board outside the readable set may be queried');
		}
		self::assertSame([7, 9], $boardFilters[0], 'the feed itself filters on exactly the readable set');
		self::assertSame([['alice']], self::boundValues($recorded['predicates'], 'in', 'ca.participant'));
	}

	public function testFindAssignedDoneSinceWithNoReadableBoardsNeverQueries(): void {
		// Denial: with no readable boards there is no query at all - a done card
		// on a board the viewer cannot read has nothing to match against, and an
		// empty set must never become `IN ()`.
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->findAssignedDoneSinceInBoards(['alice'], [], 'alice', [], 1_699_000_000, 50));
	}

	public function testFindAssignedDoneSinceWithNoIdentityNeverQueries(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->findAssignedDoneSinceInBoards([], [7], 'alice', self::roles([7]), 1_699_000_000, 50));
	}

	public function testCountByStackReportsLiveCardsOnly(): void {
		// The template card never reaches this result set (filtered in SQL); only the
		// 3 live cards of stack 5 are grouped and counted.
		$this->stubQuery([['stack_id' => 5, 'cnt' => 3]]);

		self::assertSame(
			[['stackId' => 5, 'count' => 3]],
			$this->mapper->countByStack(7, self::viewer())
		);
	}

	// ---- the rebalance's locking read + scoped write -----------------------
	//
	// SCOPE NOTE, so these are not read as more than they are: IDBConnection and
	// IQueryBuilder are mocked here, so these tests pin WHICH calls the mapper
	// makes per database provider, not what the database does with them. Two
	// things they structurally CANNOT prove and that are covered by the install
	// matrix instead (dev/smoke.sh runs occ kanso:rebalance on every DB):
	//   * that SQLite really rejects `SELECT ... FOR UPDATE` - the mock accepts it;
	//   * the method_exists() half of the guard, which closes the NC 32.0.0-32.0.6
	//     gap - a createMock() of IQueryBuilder is built from the vendored ocp
	//     stub pinned to the NEWEST server, where forUpdate() always exists.

	/**
	 * Builds a mapper whose connection reports $provider, over a QB mock that also
	 * answers forUpdate(). Returns the QB so the test can assert on it.
	 */
	private function mapperOnProvider(string $provider, ?IQueryBuilder &$qbOut = null): CardMapper {
		$qb = $this->buildQb([]);
		$qb->method('forUpdate')->willReturnSelf();
		$qbOut = $qb;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($provider);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new CardMapper($db, new CardVisibilityScope());
	}

	public function testFindByStackForUpdateTakesTheRowLockOnPostgres(): void {
		$qb = null;
		$mapper = $this->mapperOnProvider(IDBConnection::PLATFORM_POSTGRES, $qb);
		$qb->expects(self::once())->method('forUpdate');

		self::assertSame([], $mapper->findByStackForUpdate(5));
	}

	public function testFindByStackForUpdateTakesTheRowLockOnMysql(): void {
		$qb = null;
		$mapper = $this->mapperOnProvider(IDBConnection::PLATFORM_MYSQL, $qb);
		$qb->expects(self::once())->method('forUpdate');

		self::assertSame([], $mapper->findByStackForUpdate(5));
	}

	/**
	 * SQLite has no `SELECT ... FOR UPDATE`; asking for one throws
	 * "Operation 'FOR UPDATE' is not supported by platform" and takes down
	 * `occ kanso:rebalance`, the ONLY recovery from the sort-key wall. The read
	 * must therefore be issued WITHOUT the lock there - see
	 * CardMapper::supportsRowLock() for why that is still correct.
	 */
	public function testFindByStackForUpdateOmitsTheRowLockOnSqlite(): void {
		$qb = null;
		$mapper = $this->mapperOnProvider(IDBConnection::PLATFORM_SQLITE, $qb);
		$qb->expects(self::never())->method('forUpdate');

		self::assertSame([], $mapper->findByStackForUpdate(5));
	}

	/**
	 * The rebalance's write half must match on the stack as well as the card:
	 * without the row lock a card can be moved out of the stack between the read
	 * and the write, and rewriting its key would then corrupt the ordering of the
	 * stack it moved into.
	 */
	public function testUpdateSortKeyByIdIsScopedToTheStackNotJustTheCard(): void {
		$columns = [];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['update', 'set', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(self::spyExpr($columns));
		$qb->method('createNamedParameter')->willReturn('?');
		$qb->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$mapper = new CardMapper($db, new CardVisibilityScope());

		$mapper->updateSortKeyById(9, 5, 'A1');

		self::assertContains('id', $columns);
		self::assertContains(
			'stack_id',
			$columns,
			'the rebalance write must be scoped to the stack it read, not the card id alone'
		);
	}

	/**
	 * The due-reminder candidate query must be scoped to ACTIVE BOARDS (#10127):
	 * a card on a board the user archived or trashed must not push a reminder,
	 * and cards are NOT cascade-archived/trashed with their board, so the
	 * card-level flags cannot stand in for it.
	 *
	 * Scope of this guard, stated honestly: it pins that the board predicate is
	 * EMITTED, not that a database filters on it - the connection is mocked, so
	 * no SQL runs here and this cannot prove the rows are actually excluded.
	 * That half was proven end-to-end against a live instance (seeded due cards
	 * on an archived, a trashed and an active board; only the active one
	 * notified). This test exists so a future edit that silently drops the
	 * predicate goes red in CI instead of shipping.
	 */
	public function testFindDueForReminderScopesToActiveBoards(): void {
		$functions = [];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(self::exprSink());
		$qb->method('createNamedParameter')->willReturn('?');
		$qb->method('createFunction')->willReturnCallback(
			static function (string $sql) use (&$functions): string {
				$functions[] = $sql;
				return $sql;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$mapper = new CardMapper($db, new CardVisibilityScope());

		self::assertSame([], $mapper->findDueForReminder(1000, 500));

		$boardScoped = array_filter(
			$functions,
			static fn (string $sql): bool => str_starts_with($sql, 'board_id IN ('),
		);
		self::assertNotSame(
			[],
			$boardScoped,
			'the due-reminder candidate query must restrict board_id to the active-board set'
		);
	}

	/**
	 * The due-reminder candidate query must also exclude TEMPLATE cards (#10180),
	 * like every other query in this mapper. Flagging a card as a template is a
	 * pure flag flip ({@see \OCA\Kanso\Service\CardService::setTemplate()}), so a
	 * dated, assigned card keeps its due date and assignees when it becomes a
	 * blueprint - and would otherwise keep pushing bells about work nobody owes.
	 *
	 * Scope of this guard, stated honestly: it pins that the `is_template`
	 * predicate is EMITTED, not that a database filters on it - the connection is
	 * mocked, so no SQL runs here and this cannot prove the rows are actually
	 * excluded. That half was proven end-to-end against a live instance (a
	 * template card and a normal control card, both past due with the same
	 * assignee; only the control one notified). This test exists so a future edit
	 * that silently drops the predicate goes red in CI instead of shipping.
	 */
	public function testFindDueForReminderExcludesTemplates(): void {
		$this->assertFiltersTemplates(
			static fn (CardMapper $mapper): array => $mapper->findDueForReminder(1000, 500)
		);
	}

	/**
	 * Search must not hydrate whole descriptions (#10173). A description is
	 * deliberately UNCAPPED on the import/copy paths
	 * ({@see \OCA\Kanso\Service\CardService::MAX_DESCRIPTION_LENGTH}), and
	 * SearchService fetches up to 100 matching rows per request, so a `SELECT *`
	 * here makes one search cost as much as the longest description on the
	 * instance - bounded only by the importer's document-size limit. The clip is
	 * pushed into SQL so the rows never leave the database at full size.
	 *
	 * Scope of this guard, stated honestly: the connection is mocked, so this
	 * pins the SHAPE of the query - a summary column list plus a
	 * `SUBSTR(description, 1, N) AS description` alias, and no `*` - not that a
	 * database returns clipped text. The end-to-end half (an over-cap imported
	 * description still reads back in full from the card detail endpoint, while
	 * a search over it returns a short snippet) is covered by
	 * `tests/e2e/search-long-description.spec.js` against a live instance.
	 */
	public function testSearchInBoardsTruncatesTheDescriptionInSql(): void {
		$selected = [];
		$aliases = [];
		$substrings = [];

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('select')->willReturnCallback(function ($columns) use ($qb, &$selected): IQueryBuilder {
			$selected[] = $columns;
			return $qb;
		});
		$qb->method('selectAlias')->willReturnCallback(
			function ($select, $alias) use ($qb, &$aliases): IQueryBuilder {
				$aliases[$alias] = $select;
				return $qb;
			}
		);
		$qb->method('expr')->willReturn(self::exprSink());
		// A func() sink that records substring() with its real arguments; every
		// other function call keeps returning a harmless string.
		$qb->method('func')->willReturn(new class($substrings) {
			/** @param list<array{0: mixed, 1: mixed, 2: mixed}> $seen */
			public function __construct(
				private array &$seen,
			) {
			}

			public function substring($input, $start, $length = null): string {
				$this->seen[] = [$input, $start, $length];
				return 'SUBSTR(' . $input . ', ' . $start . ', ' . $length . ')';
			}

			public function __call(string $name, array $args): string {
				return '';
			}
		});
		// Identity, so the recorded substring bounds are the real values.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$mapper = new CardMapper($db, new CardVisibilityScope());

		self::assertSame([], $mapper->searchInBoards([1, 2], '%spec%', 100, 'alice', self::roles([1, 2])));

		// No `SELECT *`, and the plain column list carries no description.
		foreach ($selected as $columns) {
			self::assertNotSame('*', $columns, 'search must not select every column');
			self::assertIsArray($columns);
			self::assertNotContains('description', $columns, 'the raw description column must not be selected');
			self::assertContains('title', $columns, 'the summary columns are still selected');
		}

		// The description comes back only through a truncating alias.
		self::assertArrayHasKey('description', $aliases, 'search must alias a truncated description');
		self::assertCount(1, $substrings, 'exactly one SUBSTR() belongs in this query');
		[$input, $start, $length] = $substrings[0];
		self::assertSame('description', $input);
		self::assertSame(1, $start, 'SQL substring offsets are 1-based on every supported dialect');
		self::assertIsInt($length);
		self::assertGreaterThanOrEqual(160, $length, 'the clip must still fill a 160-character snippet');
		self::assertLessThanOrEqual(1024, $length, 'but must stay a small multiple of it, not a whole document');
		self::assertSame($aliases['description'], 'SUBSTR(description, 1, ' . $length . ')');
	}
}

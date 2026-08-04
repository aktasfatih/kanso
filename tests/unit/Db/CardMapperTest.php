<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\CardMapper;
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
		$this->mapper = new CardMapper($this->db);
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
			'select', 'selectAlias', 'addSelect', 'from', 'where', 'andWhere', 'groupBy',
			'orderBy', 'addOrderBy', 'setMaxResults',
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
		$mapper = new CardMapper($db);

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
			'select', 'selectAlias', 'addSelect', 'from', 'where', 'andWhere', 'groupBy',
			'orderBy', 'addOrderBy', 'setMaxResults',
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

		self::assertSame([7 => 5, 9 => 2], $this->mapper->countByBoards([7, 9]));
	}

	public function testCountByBoardsEmptySetShortCircuitsWithoutQuery(): void {
		// No getQueryBuilder() call is allowed for an empty set.
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->countByBoards([]));
	}

	public function testCountByBoardsOmitsBoardsWithNoOpenCards(): void {
		// Board 9 is in the requested (readable) set but produced no grouped row -
		// e.g. all its cards are archived/done-out-of-scope; it must contribute
		// nothing rather than a phantom 0-row.
		$this->stubQuery([['board_id' => 7, 'cnt' => 3]]);

		$map = $this->mapper->countByBoards([7, 9]);
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
		], $this->mapper->doneRatioByBoards([7, 9]));
	}

	public function testDoneRatioByBoardsEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->doneRatioByBoards([]));
	}

	public function testOverdueCountByBoardsGroupsCountsAcrossBoards(): void {
		$this->stubQuery([
			['board_id' => 7, 'cnt' => 1],
			['board_id' => 9, 'cnt' => 4],
		]);

		self::assertSame(
			[7 => 1, 9 => 4],
			$this->mapper->overdueCountByBoards([7, 9], new \DateTime('@1000'))
		);
	}

	public function testOverdueCountByBoardsEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->overdueCountByBoards([], new \DateTime('@1000')));
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

		$card = $this->mapper->findByBoardAndSeq(7, 123);
		self::assertNotNull($card);
		self::assertSame(42, $card->getId());
		self::assertSame('Referenced card', $card->getTitle());
		self::assertSame(123, $card->getBoardSeq());
	}

	public function testFindByBoardAndSeqReturnsNullWhenNoRow(): void {
		// No card carries that sequence on the board (unknown/deleted) → null,
		// so a stale reference falls back to plain text.
		$this->stubQuery([]);

		self::assertNull($this->mapper->findByBoardAndSeq(7, 999));
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

		$templates = $this->mapper->findTemplatesByBoard(7);
		self::assertCount(1, $templates);
		self::assertSame(42, $templates[0]->getId());
		self::assertSame('Bug report template', $templates[0]->getTitle());
		self::assertTrue($templates[0]->getIsTemplate());
	}

	public function testFindTemplatesByBoardReturnsEmptyWhenNoTemplates(): void {
		$this->stubQuery([]);
		self::assertSame([], $this->mapper->findTemplatesByBoard(7));
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
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->countByStack(7));
	}

	public function testCountByPriorityExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->countByPriority(7));
	}

	public function testAgingCountExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->agingCount(7, 1000));
	}

	public function testOverdueCountExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->overdueCount(7, new \DateTime('@1000')));
	}

	public function testDoneTimelineExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->doneTimeline(7, 0, 1000));
	}

	public function testDoneCycleTimesExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->doneCycleTimes(7, 0, 1000));
	}

	public function testCreatedTimelineExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->createdTimeline(7, 0, 1000));
	}

	public function testEstimateByStackExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->estimateByStack(7));
	}

	public function testCountByBoardsExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->countByBoards([7]));
	}

	public function testOverdueCountByBoardsExcludesTemplates(): void {
		$this->assertFiltersTemplates(fn (CardMapper $m) => $m->overdueCountByBoards([7], new \DateTime('@1000')));
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
	public function testCountByStackReportsLiveCardsOnly(): void {
		// The template card never reaches this result set (filtered in SQL); only the
		// 3 live cards of stack 5 are grouped and counted.
		$this->stubQuery([['stack_id' => 5, 'cnt' => 3]]);

		self::assertSame(
			[['stackId' => 5, 'count' => 3]],
			$this->mapper->countByStack(7)
		);
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for the checklist read order. The DB is mocked, so the
 * query builder records every ORDER BY column and the fed rows are then sorted
 * by exactly those columns, with rows the query left tied returned in the worst
 * case a DB is free to pick (reverse insertion order). Checklist items have no
 * unique sort-key index and SortKeyService::between() is deterministic, so two
 * items CAN end up sharing a sort key - a tie must not decide their order.
 */
class ChecklistItemMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private ChecklistItemMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new ChecklistItemMapper($this->db, new CardVisibilityScope());
	}

	/**
	 * A stand-in for the expression builder: its OCP interface references
	 * Doctrine symbols that are not autoloadable in the unit env, so a __call
	 * sink returns a harmless value for every builder call.
	 */
	private static function exprSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		};
	}

	/**
	 * Sorts $rows the way a DB would: by the columns the query actually ordered
	 * by, in order. Rows the ORDER BY leaves tied get the worst case a DB may
	 * legally return - reverse insertion order - so an ordering that does not
	 * fully disambiguate its rows is visibly unstable here.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param list<string> $ordering ORDER BY columns, in order
	 * @return list<array<string, mixed>>
	 */
	private static function sortLikeAdversarialDb(array $rows, array $ordering): array {
		$indexed = [];
		foreach ($rows as $i => $row) {
			$indexed[] = ['row' => $row, 'i' => $i];
		}

		usort($indexed, static function (array $a, array $b) use ($ordering): int {
			foreach ($ordering as $column) {
				// A joined query orders by `alias.column`; the fed rows are keyed by
				// the bare column name, so strip the alias or every comparison would
				// silently read null and the ordering would never be exercised.
				$key = str_contains($column, '.') ? substr($column, strpos($column, '.') + 1) : $column;
				$left = $a['row'][$key] ?? null;
				$right = $b['row'][$key] ?? null;
				$cmp = \is_int($left) && \is_int($right)
					? $left <=> $right
					: strcmp((string)$left, (string)$right);
				if ($cmp !== 0) {
					return $cmp;
				}
			}
			return $b['i'] <=> $a['i'];
		});

		return array_column($indexed, 'row');
	}

	/**
	 * A query builder that records its ORDER BY columns and, on execution,
	 * returns the fed rows sorted by exactly those columns.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function orderingQb(array $rows, ?array &$boundParams = null): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$boundParams = [];

		$ordering = [];
		$record = function (string $column, ?string $direction = null) use (&$ordering, &$qb): IQueryBuilder {
			$ordering[] = $column;
			return $qb;
		};
		$qb->method('orderBy')->willReturnCallback($record);
		$qb->method('addOrderBy')->willReturnCallback($record);
		$qb->method('expr')->willReturn(self::exprSink());
		// Record what the query BINDS, so a test can prove a filter was applied at
		// all - the expression sink above swallows the WHERE structure itself.
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (mixed $value) use (&$boundParams): string {
				$boundParams[] = $value;
				return '?';
			}
		);

		$result = $this->createMock(IResult::class);
		$queue = null;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue, $rows, &$ordering) {
			if ($queue === null) {
				$queue = self::sortLikeAdversarialDb($rows, $ordering);
			}
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	/**
	 * A spying expression builder that records every comparison as
	 * (operator, column, bound value) - the structure the plain exprSink above
	 * swallows. Lets a test assert WHICH filters a query really emits, so
	 * deleting one turns an assertion red instead of silently passing on the fed
	 * rows. Ported from the same helper in {@see CardMapperTest}.
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
	 * Runs $call against a mapper whose query builder records every predicate and
	 * feeds back $rows, and returns the predicates.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param callable(ChecklistItemMapper): mixed $call
	 * @return list<array{op: string, col: mixed, value: mixed}>
	 */
	private function recordQuery(array $rows, callable $call, mixed &$returned = null): array {
		$predicates = [];

		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'innerJoin', 'leftJoin',
			'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy', 'setMaxResults',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(self::predicateSpy($predicates));
		$qb->method('func')->willReturn(self::exprSink());
		// Identity, so the recorded predicates carry the real bound values.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$returned = $call(new ChecklistItemMapper($db, new CardVisibilityScope()));

		return $predicates;
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

	private static function viewer(int $boardId = 7, string $role = ViewerContext::ROLE_INTERNAL): ViewerContext {
		return ViewerContext::forMember('alice', $boardId, $role, true);
	}

	// ---- the overdue-step aggregate (#10696) --------------------------------

	/**
	 * Happy path: the grouped rows become a cardId => count map, so the board
	 * summary can tint one tile's checklist badge and leave the other alone.
	 */
	public function testOverdueByBoardAssemblesAPerCardCountMap(): void {
		$map = null;
		$this->recordQuery(
			[['card_id' => 3, 'cnt' => 2], ['card_id' => 9, 'cnt' => 1]],
			fn (ChecklistItemMapper $m) => $m->overdueByBoard(7, new \DateTime('@1700000000'), self::viewer()),
			$map
		);

		self::assertSame([3 => 2, 9 => 1], $map);
	}

	/**
	 * A card with no late step is simply ABSENT from the map (the summary
	 * defaults it to 0) - the grouped query never emits a zero row, so an
	 * on-track card can not pick up the tint.
	 */
	public function testOverdueByBoardOmitsCardsWithNoLateStep(): void {
		$map = null;
		$this->recordQuery(
			[['card_id' => 3, 'cnt' => 1]],
			fn (ChecklistItemMapper $m) => $m->overdueByBoard(7, new \DateTime('@1700000000'), self::viewer()),
			$map
		);

		self::assertArrayNotHasKey(9, $map);
	}

	/**
	 * The filters that make the count MEAN "overdue": the board, live cards only,
	 * the step still OPEN (dialect-safe PARAM_BOOL false, never SUM(done)), and
	 * the due date strictly before the caller's clock. Delete any one of them and
	 * its assertion here goes red - a fed-rows-only test would still pass.
	 */
	public function testOverdueByBoardCountsOnlyOpenPastDueStepsOfLiveCards(): void {
		$now = new \DateTime('@1700000000');
		$predicates = $this->recordQuery(
			[],
			fn (ChecklistItemMapper $m) => $m->overdueByBoard(7, $now, self::viewer())
		);

		// Two bindings: the aggregate's own board filter and the scope's
		// board-scoped one. Neither may name another board.
		self::assertSame([7, 7], self::boundValues($predicates, 'eq', 'c.board_id'), 'must stay board-scoped');
		self::assertSame([0], self::boundValues($predicates, 'eq', 'c.deleted_at'), 'trashed cards are not late work');
		self::assertSame([false], self::boundValues($predicates, 'eq', 'ci.done'), 'a DONE step is never overdue');
		self::assertSame([$now], self::boundValues($predicates, 'lt', 'ci.due_date'), 'and the due date must be in the past');
		self::assertSame(
			[],
			self::boundValues($predicates, 'lt', 'c.duedate'),
			'this is the STEP due date, not the card\'s own - the card signal has its own chip'
		);
	}

	/**
	 * DENIAL (#3743): the aggregate is viewer-scoped, so a card hidden from this
	 * viewer contributes nothing to their overdue signal. The three visibility
	 * branches are bound with the viewer's OWN role and uid - an internal card of
	 * the opposite side, or a private card owned by someone else, can not match.
	 * Dropping applyForViewer() empties all four assertions.
	 */
	public function testOverdueByBoardNeverCountsAStepOfACardHiddenFromTheViewer(): void {
		$predicates = $this->recordQuery(
			[],
			fn (ChecklistItemMapper $m) => $m->overdueByBoard(7, new \DateTime('@1700000000'), self::viewer(7, ViewerContext::ROLE_EXTERNAL))
		);

		self::assertSame(
			[
				CardVisibilityScope::VISIBILITY_PUBLIC,
				CardVisibilityScope::VISIBILITY_INTERNAL,
				CardVisibilityScope::VISIBILITY_PRIVATE,
			],
			self::boundValues($predicates, 'eq', 'c.visibility'),
			'the visibility rule must be applied to the joined cards table'
		);
		self::assertSame(
			[ViewerContext::ROLE_EXTERNAL],
			self::boundValues($predicates, 'eq', 'c.creator_role'),
			'the internal branch must bind the VIEWER\'s side, not a wildcard'
		);
		self::assertSame(
			['alice'],
			self::boundValues($predicates, 'eq', 'c.owner'),
			'the private branch must bind the viewer as owner'
		);
	}

	/**
	 * The board-SET twin keeps the same scoping in cross-board mode: the role that
	 * holds on EACH board is bound per side, so a viewer who is internal on one
	 * board and external on another never picks up the other side's late steps.
	 */
	public function testOverdueByBoardsScopesPerBoardRoleAndShortCircuitsOnAnEmptySet(): void {
		$map = null;
		$predicates = $this->recordQuery(
			[['card_id' => 3, 'cnt' => 4]],
			fn (ChecklistItemMapper $m) => $m->overdueByBoards(
				[7, 9],
				new \DateTime('@1700000000'),
				'alice',
				[7 => ViewerContext::ROLE_INTERNAL, 9 => ViewerContext::ROLE_EXTERNAL],
			),
			$map
		);

		self::assertSame([3 => 4], $map);
		// Several `board_id IN (…)` bindings are emitted - the aggregate's own
		// filter plus the scope's per-side internal branches. Every one of them
		// must stay inside the readable set the caller passed.
		$boardFilters = self::boundValues($predicates, 'in', 'c.board_id');
		self::assertSame([7, 9], $boardFilters[0] ?? null, 'the readable set is the outer filter');
		foreach ($boardFilters as $bound) {
			self::assertEmpty(array_diff((array)$bound, [7, 9]), 'no board outside the readable set may be queried');
		}
		self::assertSame(
			[ViewerContext::ROLE_INTERNAL, ViewerContext::ROLE_EXTERNAL],
			self::boundValues($predicates, 'eq', 'c.creator_role'),
			'each board\'s own side must be bound'
		);
		self::assertSame([false], self::boundValues($predicates, 'eq', 'ci.done'));

		// Denial: no readable boards means no query at all - never `IN ()`.
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$mapper = new ChecklistItemMapper($db, new CardVisibilityScope());
		self::assertSame([], $mapper->overdueByBoards([], new \DateTime('@1700000000'), 'alice', []));
	}

	public function testFindByCardBreaksSortKeyTiesByIdAscending(): void {
		// Two items tied on sort_key, fed in an order that is neither id order
		// nor the expected order, so only a real id tiebreaker in the query can
		// produce the assertion below.
		$this->db->method('getQueryBuilder')->willReturn($this->orderingQb([
			['id' => 5, 'card_id' => 3, 'title' => 'Second', 'sort_key' => 'mm', 'done' => false, 'created_at' => 0],
			['id' => 100, 'card_id' => 3, 'title' => 'First', 'sort_key' => 'aa', 'done' => false, 'created_at' => 0],
			['id' => 9, 'card_id' => 3, 'title' => 'Third', 'sort_key' => 'mm', 'done' => false, 'created_at' => 0],
		]));

		$ids = array_map(
			static fn ($item): int => $item->getId(),
			$this->mapper->findByCard(3)
		);

		self::assertSame(
			[100, 5, 9],
			$ids,
			'checklist items tied on sort_key must come back in a stable, id-ascending order'
		);
	}

	/**
	 * The anonymous board read (#135): one query for the whole board, grouped by
	 * card, each group in the same display order findByCard() produces - and
	 * scoped to PUBLIC cards, which is what keeps a hidden card's steps from ever
	 * being fetched on an unauthenticated endpoint.
	 */
	public function testFindByBoardPublicOnlyGroupsByCardInDisplayOrder(): void {
		$params = null;
		$this->db->method('getQueryBuilder')->willReturn($this->orderingQb([
			// Deliberately interleaved and out of order: only a real ORDER BY on
			// sort_key (with the id tiebreaker) produces the expected sequence.
			['id' => 7, 'card_id' => 3, 'title' => 'B third', 'sort_key' => 'mm', 'done' => false],
			['id' => 2, 'card_id' => 9, 'title' => 'A first', 'sort_key' => 'aa', 'done' => true],
			['id' => 4, 'card_id' => 3, 'title' => 'B first', 'sort_key' => 'ab', 'done' => true],
			['id' => 6, 'card_id' => 3, 'title' => 'B second', 'sort_key' => 'mm', 'done' => false],
		], $params));

		$map = $this->mapper->findByBoardPublicOnly(1);

		$cardIds = array_keys($map);
		sort($cardIds);
		self::assertSame([3, 9], $cardIds);
		self::assertSame(
			['B first', 'B second', 'B third'],
			array_map(static fn ($item): ?string => $item->getTitle(), $map[3]),
			'items of one card must come back in sort_key order, ties broken by id'
		);
		self::assertSame(['A first'], array_map(static fn ($item): ?string => $item->getTitle(), $map[9]));
		self::assertTrue($map[9][0]->getDone());

		// The PUBLIC-ONLY visibility scope really is applied: 'public' is bound as
		// a query parameter. Without it this method would read a private card's
		// steps on an endpoint that has no session at all.
		self::assertContains('public', $params, 'findByBoardPublicOnly must bind the public-only visibility scope');
		self::assertContains(1, $params, 'and the board it was asked for');
	}
}

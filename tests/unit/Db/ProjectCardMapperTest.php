<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level test for the owner scoping of `projectIds` (#10737).
 *
 * Projects are private, owner-only collections, but collecting a card needs
 * only READ on its board - so an unscoped `findProjectIdsByCard` told every
 * reader of a shared card how many OTHER people had filed it away privately.
 *
 * The DB is mocked (as everywhere in tests/unit/Db), so what is asserted here
 * is the shape of the query that reaches it: the join to `kanso_projects` and
 * the `p.owner = <viewer>` predicate, with the viewer's uid actually bound to
 * it. Drop either and these tests fail. The end-to-end denial - peer A's
 * project never reaching peer B - is pinned in tests/e2e/card-attr-acl.spec.js
 * against a real database and two real users.
 *
 * The second half of the file (#10734) pins the card LISTING behind every
 * project metric - that its `kanso_cards` join really carries
 * {@see CardVisibilityScope}, so a collected card that later turned hidden
 * drops out of the project view AND out of the stats derived from it.
 */
class ProjectCardMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private ProjectCardMapper $mapper;

	/** @var list<string> every where()/andWhere() predicate, in call order */
	private array $predicates = [];
	/** @var list<array<int, mixed>> every innerJoin() argument list */
	private array $joins = [];
	/** @var array<string, mixed> bound parameter token => value */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		// The REAL scope, never a mock (#10734): a createMock() here would stub out
		// the very rule the denial test below exists to pin, so the query would
		// emit no visibility branches at all and the test would pass on a leak.
		// findProjectIdsByCard() does not use the scope, so the tests above are
		// unaffected either way.
		$this->mapper = new ProjectCardMapper($this->db, new CardVisibilityScope());
	}

	/**
	 * Stubs the query builder, recording the predicates, joins and bound
	 * parameters the mapper builds, and replaying $rows as the result set.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function stubQuery(array $rows): void {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $predicate) use ($qb): IQueryBuilder {
				$this->predicates[] = $predicate;
				return $qb;
			});
		}
		$qb->method('innerJoin')->willReturnCallback(
			function (...$args) use ($qb): IQueryBuilder {
				$this->joins[] = $args;
				return $qb;
			}
		);

		// An expression sink that renders each call as `name(arg, arg)`, so a
		// predicate carries the column and the parameter token it compares.
		$qb->method('expr')->willReturn(new class {
			public function __call(string $name, array $args): string {
				return $name . '(' . implode(',', array_map(strval(...), $args)) . ')';
			}
		});
		$next = 0;
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use (&$next): string {
				$token = ':p' . $next++;
				$this->params[$token] = $value;
				return $token;
			}
		);

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	/** The predicate that compares $column, or null if the query has none. */
	private function predicateOn(string $column): ?string {
		foreach ($this->predicates as $predicate) {
			if (str_contains($predicate, $column . ',')) {
				return $predicate;
			}
		}
		return null;
	}

	public function testFindProjectIdsByCardJoinsTheProjectsTable(): void {
		$this->stubQuery([]);

		$this->mapper->findProjectIdsByCard(9, 'bob');

		self::assertCount(1, $this->joins);
		[$fromAlias, $table, $alias] = $this->joins[0];
		self::assertSame('pc', $fromAlias);
		self::assertSame('kanso_projects', $table);
		self::assertSame('p', $alias);
	}

	public function testFindProjectIdsByCardFiltersByTheViewerAsOwner(): void {
		$this->stubQuery([]);

		$this->mapper->findProjectIdsByCard(9, 'bob');

		// THE denial: without this predicate every reader of the card sees every
		// collector's project id.
		$owner = $this->predicateOn('p.owner');
		self::assertNotNull($owner, 'the query must constrain p.owner');
		self::assertStringStartsWith('eq(p.owner,', $owner);

		// …and it must be the VIEWER that is bound to it, not some other uid.
		preg_match('/eq\(p\.owner,(:p\d+)\)/', $owner, $m);
		self::assertArrayHasKey($m[1], $this->params);
		self::assertSame('bob', $this->params[$m[1]]);
	}

	public function testFindProjectIdsByCardStillFiltersByTheCard(): void {
		$this->stubQuery([]);

		$this->mapper->findProjectIdsByCard(9, 'bob');

		$card = $this->predicateOn('pc.card_id');
		self::assertNotNull($card, 'the query must constrain pc.card_id');
		preg_match('/eq\(pc\.card_id,(:p\d+)\)/', $card, $m);
		self::assertSame(9, $this->params[$m[1]]);
	}

	public function testFindProjectIdsByCardReturnsTheRemainingIdsAsInts(): void {
		$this->stubQuery([
			['project_id' => '4'],
			['project_id' => '11'],
		]);

		self::assertSame([4, 11], $this->mapper->findProjectIdsByCard(9, 'alice'));
	}

	public function testFindProjectIdsByCardReturnsNothingWhenTheViewerOwnsNone(): void {
		// The read-only peer's case: the card IS in a project, but not in one of
		// theirs, so the owner filter leaves no row at all.
		$this->stubQuery([]);

		self::assertSame([], $this->mapper->findProjectIdsByCard(9, 'bob'));
	}

	// ---- the card LISTING behind every project metric (#10734) --------------

	/**
	 * A spying expression builder recording every comparison as
	 * (operator, column, bound value) - richer than the string-rendering sink
	 * above, so the bound value of each branch is assertable. Same helper as the
	 * sibling mapper tests.
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
	 * Points the SHARED mapper from setUp() at a query builder that records every
	 * predicate, and returns them. Deliberately reuses `$this->mapper` rather than
	 * building a local one: that is what makes the setUp() construction
	 * load-bearing, so restoring the old `createMock(CardVisibilityScope::class)`
	 * there turns the denial test below red instead of leaving it green on a stub.
	 *
	 * @param callable(ProjectCardMapper): mixed $call
	 * @return list<array{op: string, col: mixed, value: mixed}>
	 */
	private function recordQuery(callable $call): array {
		$predicates = [];

		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'innerJoin', 'leftJoin',
			'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy', 'setMaxResults',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(self::predicateSpy($predicates));
		$qb->method('func')->willReturn(new class {
			public function __call(string $name, array $args): string {
				return 'fn';
			}
		});
		// Identity, so the recorded predicates carry the real bound values.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
		$call($this->mapper);

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

	/**
	 * DENIAL: COLLECTING a card into a project is a one-time act, so a card that
	 * was public when filed away can turn private later. This listing is what
	 * drops it again - and because every project metric aggregates over exactly
	 * the card id set this returns, an unscoped listing would leak the hidden
	 * card twice: by name in the project view, and by arithmetic in the stats.
	 *
	 * The role that holds on EACH board is bound per side. Remove the apply()
	 * call and every assertion below goes red.
	 */
	public function testFindCardsInProjectAndBoardsNeverListsAHiddenCardAndShortCircuitsOnAnEmptySet(): void {
		$predicates = $this->recordQuery(
			fn (ProjectCardMapper $m) => $m->findCardsInProjectAndBoards(
				4,
				[7, 9],
				'alice',
				[7 => ViewerContext::ROLE_INTERNAL, 9 => ViewerContext::ROLE_EXTERNAL],
			)
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
			[ViewerContext::ROLE_INTERNAL, ViewerContext::ROLE_EXTERNAL],
			self::boundValues($predicates, 'eq', 'c.creator_role'),
			'both sides must be bound - the board-to-side PAIRING is pinned by CardVisibilityScopeTest'
		);
		self::assertSame(
			['alice'],
			self::boundValues($predicates, 'eq', 'c.owner'),
			'the private branch must bind the viewer as owner'
		);

		// Several `board_id IN (…)` bindings: the listing's own readable-set filter
		// plus the scope's per-side lists. None may leave the set.
		$boardFilters = self::boundValues($predicates, 'in', 'c.board_id');
		self::assertSame([7, 9], $boardFilters[0] ?? null, 'the readable set is the outer filter');
		self::assertCount(3, $boardFilters, 'plus one per-side list from the scope');
		foreach ($boardFilters as $bound) {
			self::assertEmpty(array_diff((array)$bound, [7, 9]), 'no board outside the readable set may be queried');
		}

		// Denial of the degenerate call: no readable board means no query at all -
		// never an `IN ()`.
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$mapper = new ProjectCardMapper($db, new CardVisibilityScope());
		self::assertSame([], $mapper->findCardsInProjectAndBoards(4, [], 'alice', []));
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ProjectAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ProjectShareMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\PermissionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shape of the queries that reach the DB: `projectIds` covers only the
 * projects the viewer can reach, and the card listing behind every project
 * metric carries {@see CardVisibilityScope}. The end-to-end denial runs in
 * tests/e2e/card-attr-acl.spec.js.
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
	/** @var list<string> every select() column */
	private array $selected = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		// The REAL scope, never a mock (#10734): a createMock() here would stub out
		// the very rule the denial test below exists to pin, so the query would
		// emit no visibility branches at all and the test would pass on a leak.
		// findProjectIdsByCard() does not use the scope, so the tests above are
		// unaffected either way.
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('getUserGroupIds')->willReturn(['staff']);
		$this->mapper = new ProjectCardMapper(
			$this->db,
			new CardVisibilityScope(),
			new ProjectAccess($this->db, $this->createMock(ProjectShareMapper::class), $permissions),
		);
	}

	/**
	 * Stubs the query builder, recording the predicates, joins and bound
	 * parameters the mapper builds, and replaying $rows as the result set.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function stubQuery(array $rows): void {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['from', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('select')->willReturnCallback(function (string ...$columns) use ($qb): IQueryBuilder {
			array_push($this->selected, ...$columns);
			return $qb;
		});
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
		$result->method('fetchAll')->willReturn($rows);
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

	public function testFindProjectIdsByCardReachesProjectsTheViewerOwns(): void {
		$this->stubQuery([]);

		$this->mapper->findProjectIdsByCard(9, 'bob');

		$owner = $this->predicateOn('p.owner');
		self::assertNotNull($owner, 'the query must constrain p.owner');

		preg_match('/eq\(p\.owner,(:p\d+)\)/', $owner, $m);
		self::assertArrayHasKey($m[1], $this->params);
		self::assertSame('bob', $this->params[$m[1]]);
	}

	public function testFindProjectIdsByCardAlsoReachesProjectsSharedWithTheViewer(): void {
		$this->stubQuery([]);

		$this->mapper->findProjectIdsByCard(9, 'bob');

		$share = $this->predicateOn('s.participant');
		self::assertNotNull($share, 'the share subquery must constrain s.participant');
		preg_match('/eq\(s\.participant,(:p\d+)\)/', $share, $user);
		preg_match('/in\(s\.participant,(:p\d+)\)/', $share, $groups);
		self::assertSame('bob', $this->params[$user[1]]);
		self::assertSame(['staff'], $this->params[$groups[1]]);
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

	public function testBoardDigestCoversWhatTheViewerSeesOfProjects(): void {
		$this->stubQuery([['card_id' => 9, 'id' => 4, 'title' => 'Q3', 'color' => 'ff0000']]);

		self::assertNotSame('', $this->mapper->boardDigest(ViewerContext::forMember('bob', 7, ViewerContext::ROLE_INTERNAL, false)));

		self::assertSame(['pc.card_id', 'p.id', 'p.title', 'p.color'], array_slice($this->selected, 0, 4));
		preg_match('/eq\(c\.board_id,(:p\d+)\)/', (string)$this->predicateOn('c.board_id'), $board);
		self::assertSame(7, $this->params[$board[1]]);
		preg_match('/eq\(c\.deleted_at,(:p\d+)\)/', (string)$this->predicateOn('c.deleted_at'), $deleted);
		self::assertSame(0, $this->params[$deleted[1]]);
	}

	public function testBoardDigestIsScopedToWhatTheViewerCanReach(): void {
		$this->stubQuery([]);

		$this->mapper->boardDigest(ViewerContext::forMember('bob', 7, ViewerContext::ROLE_INTERNAL, false));

		preg_match('/eq\(p\.owner,(:p\d+)\)/', (string)$this->predicateOn('p.owner'), $owner);
		self::assertSame('bob', $this->params[$owner[1]]);
		preg_match('/in\(s\.participant,(:p\d+)\)/', (string)$this->predicateOn('s.participant'), $groups);
		self::assertSame(['staff'], $this->params[$groups[1]]);
	}

	public function testBoardDigestLeavesOutCardsHiddenFromTheViewer(): void {
		$this->stubQuery([]);

		$this->mapper->boardDigest(ViewerContext::forMember('bob', 7, ViewerContext::ROLE_EXTERNAL, false));

		self::assertNotNull($this->predicateOn('c.visibility'));
	}

	public function testBoardDigestIsEmptyWithoutMemberships(): void {
		$this->stubQuery([]);

		self::assertSame('', $this->mapper->boardDigest(ViewerContext::forMember('bob', 7, ViewerContext::ROLE_INTERNAL, false)));
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
		$mapper = new ProjectCardMapper($db, new CardVisibilityScope(), $this->createMock(ProjectAccess::class));
		self::assertSame([], $mapper->findCardsInProjectAndBoards(4, [], 'alice', []));
	}

	/**
	 * The project card row carries the board's `prefix` (as `boardPrefix`)
	 * alongside the card's `boardSeq` - the two halves of the card's human
	 * reference (e.g. KANSO-359) that the project view renders on each row.
	 * A row without a per-board number maps to a null boardSeq (a
	 * pre-migration card), so the client hides the chip rather than render a
	 * broken id.
	 */
	public function testFindCardsInProjectAndBoardsCarriesTheBoardPrefixForTheCardReference(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'innerJoin', 'leftJoin',
			'where', 'andWhere', 'orderBy', 'addOrderBy',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(new class {
			public function __call(string $name, array $args): string {
				return $name . '(' . implode(',', array_map(strval(...), $args)) . ')';
			}
		});
		$next = 0;
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) use (&$next): string {
			return ':p' . $next++;
		});

		$result = $this->createMock(IResult::class);
		$queue = [[
			'id' => '9',
			'board_id' => '1',
			'board_title' => 'Kanban',
			'board_prefix' => 'KANSO',
			'board_seq' => '359',
			'stack_title' => 'To do',
			'title' => 'Ship it',
			'duedate' => null,
			'start_date' => null,
			'priority' => '0',
			'done_at' => '0',
			'started_at' => '0',
			'parent_card_id' => null,
			'sort_key' => 'a',
			'estimate' => null,
			'estimate_scale' => 'none',
			'estimate_custom' => null,
			'disabled_card_features' => null,
			'created_at' => '100',
			'added_at' => '200',
		]];
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$rows = $this->mapper->findCardsInProjectAndBoards(5, [1], 'alice', [1 => ViewerContext::ROLE_INTERNAL]);

		self::assertCount(1, $rows);
		self::assertSame('KANSO', $rows[0]['boardPrefix'], 'the board prefix rides the project card row');
		self::assertSame(359, $rows[0]['boardSeq'], 'the per-board number rides the project card row');
		self::assertSame('Kanban', $rows[0]['boardTitle']);
		self::assertSame('To do', $rows[0]['stackTitle']);
	}

	public function testOpenBlockerCountsOnlyCountsOpenBlockersTheViewerCanSee(): void {
		$predicates = $this->recordQuery(
			fn (ProjectCardMapper $m) => $m->openBlockerCounts([11, 12], [7], 'alice', [7 => ViewerContext::ROLE_INTERNAL])
		);

		self::assertSame(
			[
				CardVisibilityScope::VISIBILITY_PUBLIC,
				CardVisibilityScope::VISIBILITY_INTERNAL,
				CardVisibilityScope::VISIBILITY_PRIVATE,
			],
			self::boundValues($predicates, 'eq', 'src.visibility'),
		);
		self::assertSame(['alice'], self::boundValues($predicates, 'eq', 'src.owner'));
		self::assertSame([0], self::boundValues($predicates, 'eq', 'src.done_at'));
		self::assertSame([[11, 12]], self::boundValues($predicates, 'in', 'r.other_card_id'));
		self::assertSame([7], self::boundValues($predicates, 'in', 'src.board_id')[0] ?? null);

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$mapper = new ProjectCardMapper($db, new CardVisibilityScope(), $this->createMock(ProjectAccess::class));
		self::assertSame([], $mapper->openBlockerCounts([], [7], 'alice', []));
		self::assertSame([], $mapper->openBlockerCounts([11], [], 'alice', []));
	}
}

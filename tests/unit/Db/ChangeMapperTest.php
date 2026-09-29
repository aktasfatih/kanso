<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for the delta-sync reads (#3675). The DB is mocked so these
 * verify the pure-PHP contract: findSince hydrates the change rows a `?since=`
 * window returns (ordered/filtered by SQL, asserted at the e2e layer), and
 * getOldestChangeId maps MIN(id) / no-rows to an int (0 for an empty board).
 */
class ChangeMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private ChangeMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new ChangeMapper($this->db, new CardVisibilityScope(), new ChangeDetailMapper($this->db));
	}

	private static function exprSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		};
	}

	/**
	 * A spying expression builder recording the column of each comparison, so a
	 * test can assert findSince emits the `id` (id > since) and `board_id` filters
	 * the delta window needs.
	 *
	 * @param array<int, string> $collector
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
	 * A fluent query-builder mock that ignores chained calls and, on
	 * executeQuery(), returns a result iterating $rows once (for findEntities) or
	 * yielding $one from fetchOne() (for the MAX/MIN aggregates).
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param array<int, string> $columns filled with each filtered column when passed
	 */
	private function buildQb(array $rows, mixed $one = false, ?array &$columns = null): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($columns !== null ? self::spyExpr($columns) : self::exprSink());
		$qb->method('func')->willReturn(self::exprSink());
		$qb->method('createNamedParameter')->willReturn('?');
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$result->method('fetchOne')->willReturn($one);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	public function testFindSinceHydratesTheWindowRows(): void {
		// Two rows newer than the cursor come back as hydrated Change entities.
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([
			['id' => 6, 'board_id' => 7, 'entity_type' => Change::ENTITY_CARD, 'entity_id' => 42, 'action' => Change::ACTION_UPDATE, 'actor' => 'alice', 'created_at' => 1000],
			['id' => 7, 'board_id' => 7, 'entity_type' => Change::ENTITY_STACK, 'entity_id' => 3, 'action' => Change::ACTION_CREATE, 'actor' => 'alice', 'created_at' => 1001],
		]));

		$rows = $this->mapper->findSince(7, 5);
		self::assertCount(2, $rows);
		self::assertSame(6, $rows[0]->getId());
		self::assertSame(Change::ENTITY_CARD, $rows[0]->getEntityType());
		self::assertSame(42, $rows[0]->getEntityId());
		self::assertSame(7, $rows[1]->getId());
		self::assertSame(Change::ENTITY_STACK, $rows[1]->getEntityType());
	}

	public function testFindSinceFiltersOnBoardAndCursor(): void {
		$columns = [];
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([], false, $columns));

		$this->mapper->findSince(7, 5);

		// The window read is bounded to the board and to rows newer than the cursor.
		self::assertContains('board_id', $columns);
		self::assertContains('id', $columns);
	}

	public function testFindSinceEmptyWindowReturnsEmpty(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([]));
		self::assertSame([], $this->mapper->findSince(7, 999));
	}

	public function testGetOldestChangeIdReturnsMin(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([], '3'));
		self::assertSame(3, $this->mapper->getOldestChangeId(7));
	}

	public function testGetOldestChangeIdEmptyBoardIsZero(): void {
		// No rows → MIN(id) is null → 0 (mirrors getLatestChangeId's empty case).
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([], false));
		self::assertSame(0, $this->mapper->getOldestChangeId(7));
	}

	// ---- the visibility filter on the Inbox feed (#10734) -------------------

	/**
	 * A spying expression builder recording every comparison as
	 * (operator, column, bound value) - the bound values the column-only spyExpr
	 * above throws away. Same helper as the sibling mapper tests.
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
	 * Runs $call against a mapper whose query builder records every predicate.
	 *
	 * @param callable(ChangeMapper): mixed $call
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
		$qb->method('func')->willReturn(self::exprSink());
		// Identity, so the recorded predicates carry the real bound values.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$call(new ChangeMapper($db, new CardVisibilityScope(), new ChangeDetailMapper($db)));

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
	 * DENIAL: a change row on its own carries no title, but the Inbox feed
	 * enriches it with the card title and board title - so an unscoped read
	 * announces a card the viewer may not see, plus what was just done to it.
	 * FOLLOWING a card is not visibility, so a followed card that later turned
	 * private must stop feeding the inbox.
	 *
	 * The role that holds on EACH board is bound per side. Remove the apply()
	 * call from findInboxForCards() and every assertion below goes red.
	 */
	public function testFindInboxForCardsNeverFeedsAChangeOnAHiddenCardAndShortCircuitsOnAnEmptySet(): void {
		$predicates = $this->recordQuery(
			fn (ChangeMapper $m) => $m->findInboxForCards(
				[3, 9],
				[7, 9],
				'alice',
				[Change::VERB_ASSIGNED],
				25,
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

		// Several `board_id IN (…)` bindings: the feed's own readable-set filter
		// plus the scope's per-side lists. None may leave the set.
		$boardFilters = self::boundValues($predicates, 'in', 'c.board_id');
		self::assertSame([7, 9], $boardFilters[0] ?? null, 'the readable set is the outer filter');
		self::assertCount(3, $boardFilters, 'plus one per-side list from the scope');
		foreach ($boardFilters as $bound) {
			self::assertEmpty(array_diff((array)$bound, [7, 9]), 'no board outside the readable set may be queried');
		}

		// Denial of the degenerate call: an empty card, board or verb set means no
		// query at all - never an `IN ()`.
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$mapper = new ChangeMapper($db, new CardVisibilityScope(), new ChangeDetailMapper($db));
		self::assertSame([], $mapper->findInboxForCards([], [7], 'alice', [Change::VERB_ASSIGNED], 25, [7 => ViewerContext::ROLE_INTERNAL]));
		self::assertSame([], $mapper->findInboxForCards([3], [], 'alice', [Change::VERB_ASSIGNED], 25, []));
		self::assertSame([], $mapper->findInboxForCards([3], [7], 'alice', [], 25, [7 => ViewerContext::ROLE_INTERNAL]));
	}
}

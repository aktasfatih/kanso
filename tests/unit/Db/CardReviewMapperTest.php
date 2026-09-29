<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level test for the boards-list needs-review aggregate (#3571). The DB is
 * mocked so this verifies the pure-PHP contract: the grouped rows are assembled
 * into a boardId => count map across MULTIPLE boards, an empty (ACL-resolved) set
 * short-circuits without a query, and a board that produced no grouped row (none
 * of its cards need review, OR it is outside the fed readable set) contributes
 * nothing.
 */
class CardReviewMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private CardReviewMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new CardReviewMapper($this->db, new CardVisibilityScope());
	}

	/**
	 * The viewer's cross-board role map for the visibility-scoped aggregate
	 * (#3743) - the scope only appends extra WHERE branches, which the fluent
	 * QB mock absorbs; the fed grouped rows are what each assertion pins.
	 *
	 * @param int[] $boardIds
	 * @return array<int, string>
	 */
	private static function roles(array $boardIds): array {
		return array_fill_keys($boardIds, ViewerContext::ROLE_INTERNAL);
	}

	/**
	 * A stand-in for the expression / function builders (see CardMapperTest): any
	 * method call returns an empty string, avoiding a createMock() on the OCP
	 * builder interfaces (which reference non-autoloadable Doctrine symbols).
	 */
	private static function exprSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		};
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function stubQuery(array $rows): void {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'addSelect', 'from', 'innerJoin', 'where', 'andWhere', 'groupBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
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

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	public function testNeedsReviewCountByBoardsGroupsAcrossBoards(): void {
		$this->stubQuery([
			['board_id' => 7, 'cnt' => 3],
			['board_id' => 9, 'cnt' => 1],
		]);

		self::assertSame([7 => 3, 9 => 1], $this->mapper->needsReviewCountByBoards([7, 9], 'alice', self::roles([7, 9])));
	}

	public function testNeedsReviewCountByBoardsEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->needsReviewCountByBoards([], 'alice', []));
	}

	public function testNeedsReviewCountByBoardsOmitsBoardsWithNoOpenReviews(): void {
		// Board 9 is in the requested set but has no not-approved reviews - it
		// yields no grouped row and must be absent from the map (defaults to 0).
		$this->stubQuery([['board_id' => 7, 'cnt' => 2]]);

		$map = $this->mapper->needsReviewCountByBoards([7, 9], 'alice', self::roles([7, 9]));
		self::assertSame([7 => 2], $map);
		self::assertArrayNotHasKey(9, $map);
	}

	// ---- the visibility filter on the aggregate (#10734) --------------------

	/**
	 * A spying expression builder recording every comparison as
	 * (operator, column, bound value) - the structure the plain exprSink above
	 * swallows. Lets a test assert WHICH filters the query really emits, so
	 * deleting one turns an assertion red instead of silently passing on the fed
	 * rows. Same helper as the sibling mapper tests.
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
	 * @param callable(CardReviewMapper): mixed $call
	 * @return list<array{op: string, col: mixed, value: mixed}>
	 */
	private function recordQuery(callable $call): array {
		$predicates = [];

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'addSelect', 'from', 'innerJoin', 'where', 'andWhere', 'groupBy'] as $method) {
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
		$call(new CardReviewMapper($db, new CardVisibilityScope()));

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
	 * DENIAL: being ASKED for a review grants no visibility over the card, so
	 * the boards-list needs-review badge is viewer-scoped. Without it, a review
	 * request on a private or opposite-side card would raise a board's badge -
	 * telling the viewer that hidden work is waiting on someone.
	 *
	 * The role that holds on EACH board is bound per side, so a viewer who is
	 * internal on one board and external on another never picks up the other
	 * side's open reviews. Remove the apply() call and every assertion below
	 * goes red; the fed-rows tests above would not notice.
	 */
	public function testNeedsReviewCountByBoardsNeverCountsACardHiddenFromTheViewer(): void {
		$predicates = $this->recordQuery(
			fn (CardReviewMapper $m) => $m->needsReviewCountByBoards(
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

		// Several `board_id IN (…)` bindings: the aggregate's own readable-set
		// filter plus the scope's per-side lists. None may leave the set.
		$boardFilters = self::boundValues($predicates, 'in', 'c.board_id');
		self::assertSame([7, 9], $boardFilters[0] ?? null, 'the readable set is the outer filter');
		self::assertCount(3, $boardFilters, 'plus one per-side list from the scope');
		foreach ($boardFilters as $bound) {
			self::assertEmpty(array_diff((array)$bound, [7, 9]), 'no board outside the readable set may be queried');
		}
	}
}

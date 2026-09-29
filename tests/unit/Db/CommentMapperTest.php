<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for the two viewer-scoped comment reads (#10734): the
 * Inbox feed and the board "comment activity" count.
 *
 * A comment carries its card's title and board title into the feed, so an
 * unscoped comment read leaks the hidden CARD, not just the remark. The DB is
 * mocked and the query builder records every predicate with its bound value, so
 * these assert WHICH filters the SQL really emits - delete the
 * {@see CardVisibilityScope} call from either query and its test goes red, where
 * a fed-rows test would keep passing (the rows come back regardless of the
 * WHERE, and they cannot tell you whether the hidden card's comment was
 * excluded by the DB or simply never fed).
 *
 * The end-to-end half - a real second user, real SQL, a denied search hit -
 * lives in tests/e2e/visibility-leak-matrix.spec.js.
 */
class CommentMapperTest extends TestCase {
	/**
	 * A spying expression builder recording every comparison as
	 * (operator, column, bound value). The OCP expression interface references
	 * Doctrine symbols that are not autoloadable in the unit env, so this is a
	 * __call sink - the same helper the sibling mapper tests use.
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

	/** A harmless stand-in for the function builder (COUNT(*) and friends). */
	private static function funcSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return 'fn';
			}
		};
	}

	/**
	 * Runs $call against a mapper whose query builder records every predicate.
	 *
	 * @param callable(CommentMapper): mixed $call
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
		$qb->method('func')->willReturn(self::funcSink());
		// Identity, so the recorded predicates carry the real bound values.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('createFunction')->willReturn('fn');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$call(new CommentMapper($db, new CardVisibilityScope()));

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
	 * DENIAL: a followed card that later turned private (or flipped to the other
	 * side) must stop feeding the Inbox. FOLLOWING a card is not visibility, and
	 * the feed row carries the card title and board title - so an unscoped read
	 * announces the hidden card, not merely a comment on it.
	 *
	 * Remove the `apply()` call from findInboxForCards() and every assertion
	 * below goes red: the three visibility values, both per-board sides and the
	 * owner all disappear from the query.
	 */
	public function testFindInboxForCardsNeverFeedsACommentOnAHiddenCardAndShortCircuitsOnAnEmptySet(): void {
		$predicates = $this->recordQuery(
			fn (CommentMapper $m) => $m->findInboxForCards(
				[3, 9],
				[7, 9],
				'alice',
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
		// plus the scope's per-side lists. None may name a board outside the set.
		$boardFilters = self::boundValues($predicates, 'in', 'c.board_id');
		self::assertSame([7, 9], $boardFilters[0] ?? null, 'the readable set is the outer filter');
		self::assertCount(3, $boardFilters, 'plus one per-side list from the scope');
		foreach ($boardFilters as $bound) {
			self::assertEmpty(array_diff((array)$bound, [7, 9]), 'no board outside the readable set may be queried');
		}

		// Denial of the degenerate call: nothing followed, or no readable board,
		// means no query at all - never an `IN ()`.
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$mapper = new CommentMapper($db, new CardVisibilityScope());
		self::assertSame([], $mapper->findInboxForCards([], [7], 'alice', 25, [7 => ViewerContext::ROLE_INTERNAL]));
		self::assertSame([], $mapper->findInboxForCards([3], [], 'alice', 25, []));
	}

	/**
	 * DENIAL: the board's "comment activity" figure is a count, and a count is a
	 * leak too - it would tell the viewer how much discussion is happening on
	 * cards they may not see. Dropping applyForViewer() empties all four
	 * assertions below.
	 */
	public function testCountRecentForBoardNeverCountsACommentOnACardHiddenFromTheViewer(): void {
		$predicates = $this->recordQuery(
			fn (CommentMapper $m) => $m->countRecentForBoard(
				7,
				1_700_000_000,
				ViewerContext::forMember('exty', 7, ViewerContext::ROLE_EXTERNAL, true),
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
			[ViewerContext::ROLE_EXTERNAL],
			self::boundValues($predicates, 'eq', 'c.creator_role'),
			'the internal branch must bind the VIEWER\'s side, not a wildcard'
		);
		self::assertSame(
			['exty'],
			self::boundValues($predicates, 'eq', 'c.owner'),
			'the private branch must bind the viewer as owner'
		);
		// Two board bindings: the count's own filter and the scope's.
		self::assertSame([7, 7], self::boundValues($predicates, 'eq', 'c.board_id'), 'must stay board-scoped');
	}
}

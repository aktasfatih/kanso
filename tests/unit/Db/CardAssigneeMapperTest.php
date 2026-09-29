<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level test for the per-assignee estimate aggregate (#10734).
 *
 * The DB is mocked and the query builder records every predicate with its bound
 * value, so this asserts WHICH filters the SQL really emits - delete the
 * {@see CardVisibilityScope} call and the test goes red, where a fed-rows test
 * would keep passing (the rows come back regardless of the WHERE).
 *
 * The per-assignee CARD COUNT twin ({@see CardAssigneeMapper::countByAssigneeForBoard()})
 * is pinned end-to-end instead, by the by-assignee assertions in
 * tests/e2e/visibility-leak-matrix.spec.js against real SQL and two real users.
 */
class CardAssigneeMapperTest extends TestCase {
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

	/**
	 * Runs $call against a mapper whose query builder records every predicate.
	 *
	 * @param callable(CardAssigneeMapper): mixed $call
	 * @return list<array{op: string, col: mixed, value: mixed}>
	 */
	private function recordQuery(callable $call): array {
		$predicates = [];

		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'innerJoin',
			'where', 'andWhere', 'groupBy', 'orderBy', 'addOrderBy',
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

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$call(new CardAssigneeMapper($db, new CardVisibilityScope()));

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
	 * DENIAL: the per-assignee estimate sum is derived from raw estimate tokens
	 * paired with a participant, so an unscoped read would not just inflate
	 * someone's workload bar - it would tell the viewer that a person is
	 * carrying points on a card the viewer may not see.
	 *
	 * Remove the applyForViewer() call from estimateByAssigneeForBoard() and
	 * every assertion below goes red.
	 */
	public function testEstimateByAssigneeForBoardNeverSumsACardHiddenFromTheViewer(): void {
		$predicates = $this->recordQuery(
			fn (CardAssigneeMapper $m) => $m->estimateByAssigneeForBoard(
				7,
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
		// Two board bindings: the aggregate's own filter and the scope's - the
		// latter is what stops a drifted card row crossing boards.
		self::assertSame([7, 7], self::boundValues($predicates, 'eq', 'c.board_id'), 'must stay board-scoped');
	}
}

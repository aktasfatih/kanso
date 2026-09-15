<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

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

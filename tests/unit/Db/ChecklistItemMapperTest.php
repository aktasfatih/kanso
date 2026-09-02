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
				$left = $a['row'][$column] ?? null;
				$right = $b['row'][$column] ?? null;
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
	private function orderingQb(array $rows): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$ordering = [];
		$record = function (string $column, ?string $direction = null) use (&$ordering, &$qb): IQueryBuilder {
			$ordering[] = $column;
			return $qb;
		};
		$qb->method('orderBy')->willReturnCallback($record);
		$qb->method('addOrderBy')->willReturnCallback($record);
		$qb->method('expr')->willReturn(self::exprSink());
		$qb->method('createNamedParameter')->willReturn('?');

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
}

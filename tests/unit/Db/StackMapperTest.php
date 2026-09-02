<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\StackMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for the delta-sync stack read (#3675). The DB is mocked, so
 * these verify the pure-PHP contract of findByIds: an explicit id set hydrates
 * the matching stacks, and an empty set short-circuits without touching the DB
 * (never emit `IN ()`, which is invalid SQL).
 *
 * The ordering tests additionally pin the column order the stack reads ASK the
 * DB for: the query builder records every ORDER BY and the fed rows are then
 * sorted by exactly those columns, with rows the query left tied returned in the
 * worst-case order a DB is free to pick (see {@see self::sortLikeAdversarialDb()}).
 * Duplicate stack sort keys are reachable - stacks have no unique sort-key index
 * and SortKeyService::between() is deterministic - so a tie must not decide the
 * column order.
 */
class StackMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private StackMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new StackMapper($this->db);
	}

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
	private function buildQb(array $rows): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(self::exprSink());
		$qb->method('createNamedParameter')->willReturn('?');

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	public function testFindByIdsHydratesTheRequestedStacks(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([
			['id' => 3, 'board_id' => 7, 'title' => 'Doing', 'sort_key' => 'aa', 'deleted_at' => 0],
		]));

		$stacks = $this->mapper->findByIds(7, [3]);
		self::assertCount(1, $stacks);
		self::assertSame(3, $stacks[0]->getId());
		self::assertSame('Doing', $stacks[0]->getTitle());
	}

	public function testFindByIdsEmptySetShortCircuitsWithoutQuery(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');
		self::assertSame([], $this->mapper->findByIds(7, []));
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

	/**
	 * Two stacks tied on sort_key, fed in an order that is neither id order nor
	 * the expected order, so only a real id tiebreaker in the query can produce
	 * the assertion below.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function tiedStackRows(int $role = 0): array {
		return [
			['id' => 5, 'board_id' => 7, 'title' => 'Doing', 'sort_key' => 'mm', 'deleted_at' => 0, 'role' => $role],
			['id' => 100, 'board_id' => 7, 'title' => 'Todo', 'sort_key' => 'aa', 'deleted_at' => 0, 'role' => $role],
			['id' => 9, 'board_id' => 7, 'title' => 'Review', 'sort_key' => 'mm', 'deleted_at' => 0, 'role' => $role],
		];
	}

	public function testFindByBoardBreaksSortKeyTiesByIdAscending(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->orderingQb(self::tiedStackRows()));

		$ids = array_map(
			static fn ($stack): int => $stack->getId(),
			$this->mapper->findByBoard(7)
		);

		self::assertSame(
			[100, 5, 9],
			$ids,
			'stacks tied on sort_key must come back in a stable, id-ascending order'
		);
	}

	public function testFindByBoardAndRoleResolvesTiesToTheLowestId(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->orderingQb([
			['id' => 11, 'board_id' => 7, 'title' => 'Done', 'sort_key' => 'mm', 'deleted_at' => 0, 'role' => 2],
			['id' => 42, 'board_id' => 7, 'title' => 'Done (dup)', 'sort_key' => 'mm', 'deleted_at' => 0, 'role' => 2],
		]));

		$stack = $this->mapper->findByBoardAndRole(7, 2);

		self::assertNotNull($stack);
		self::assertSame(
			11,
			$stack->getId(),
			'a role lookup tied on sort_key must always resolve to the same stack'
		);
	}
}

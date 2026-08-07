<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
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
		$this->mapper = new ChangeMapper($this->db);
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
}

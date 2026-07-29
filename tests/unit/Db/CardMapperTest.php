<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\CardMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for the boards-list per-board aggregates (#3571). The DB is
 * mocked so these verify the pure-PHP contract of each aggregate: the grouped
 * rows are assembled into a boardId => count map (correct across MULTIPLE boards),
 * an empty board-id set short-circuits without touching the DB, and only boards
 * present in the grouped rows contribute - a board outside the fed set (which, in
 * production, is the caller's ACL-resolved readable set) yields no entry.
 */
class CardMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private CardMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new CardMapper($this->db);
	}

	/**
	 * A stand-in for the expression / function builders: any method call returns
	 * an empty string (the mappers only pass these into where()/select(), which
	 * are self-returning no-ops in these tests).
	 */
	private static function exprSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		};
	}

	/**
	 * Builds a fluent query-builder mock that ignores every chained call and, on
	 * executeQuery(), returns a result iterating the given rows once.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function buildQb(array $rows): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'selectAlias', 'addSelect', 'from', 'where', 'andWhere', 'groupBy',
		] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		// expr()/func() are NOT mocked via createMock(): their OCP interfaces
		// reference Doctrine ExpressionBuilder symbols that are not autoloadable in
		// the unit env, so reflecting them fatals. A __call sink returns a harmless
		// value for every builder call the queries make.
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

		return $qb;
	}

	/**
	 * Single-query aggregate: every getQueryBuilder() returns one QB over $rows.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function stubQuery(array $rows): void {
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb($rows));
	}

	/**
	 * Multi-query aggregate: successive getQueryBuilder() calls return QBs over
	 * the given row sets in order (used by the two-query doneRatioByBoards).
	 *
	 * @param list<list<array<string, mixed>>> $rowSets
	 */
	private function stubQueries(array $rowSets): void {
		$qbs = array_map(fn (array $rows): IQueryBuilder => $this->buildQb($rows), $rowSets);
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$qbs);
	}

	public function testCountByBoardsGroupsCountsAcrossBoards(): void {
		$this->stubQuery([
			['board_id' => 7, 'cnt' => 5],
			['board_id' => 9, 'cnt' => 2],
		]);

		self::assertSame([7 => 5, 9 => 2], $this->mapper->countByBoards([7, 9]));
	}

	public function testCountByBoardsEmptySetShortCircuitsWithoutQuery(): void {
		// No getQueryBuilder() call is allowed for an empty set.
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->countByBoards([]));
	}

	public function testCountByBoardsOmitsBoardsWithNoOpenCards(): void {
		// Board 9 is in the requested (readable) set but produced no grouped row -
		// e.g. all its cards are archived/done-out-of-scope; it must contribute
		// nothing rather than a phantom 0-row.
		$this->stubQuery([['board_id' => 7, 'cnt' => 3]]);

		$map = $this->mapper->countByBoards([7, 9]);
		self::assertSame([7 => 3], $map);
		self::assertArrayNotHasKey(9, $map);
	}

	public function testDoneRatioByBoardsPairsTotalsWithDonePerBoard(): void {
		// First query = totals, second query = done-only, both grouped by board.
		$this->stubQueries([
			[['board_id' => 7, 'cnt' => 5], ['board_id' => 9, 'cnt' => 4]],
			[['board_id' => 7, 'cnt' => 2]],
		]);

		self::assertSame([
			// board 7: 2 of 5 done; board 9: 0 done (absent from the done map).
			7 => ['total' => 5, 'done' => 2],
			9 => ['total' => 4, 'done' => 0],
		], $this->mapper->doneRatioByBoards([7, 9]));
	}

	public function testDoneRatioByBoardsEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->doneRatioByBoards([]));
	}

	public function testOverdueCountByBoardsGroupsCountsAcrossBoards(): void {
		$this->stubQuery([
			['board_id' => 7, 'cnt' => 1],
			['board_id' => 9, 'cnt' => 4],
		]);

		self::assertSame(
			[7 => 1, 9 => 4],
			$this->mapper->overdueCountByBoards([7, 9], new \DateTime('@1000'))
		);
	}

	public function testOverdueCountByBoardsEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->overdueCountByBoards([], new \DateTime('@1000')));
	}
}

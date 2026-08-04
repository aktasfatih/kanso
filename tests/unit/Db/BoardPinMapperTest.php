<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\BoardPinMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level test for the batched per-user `pinned` flag on the boards-list
 * payload (#3632). The DB is mocked so this verifies the pure-PHP contract: the
 * rows are assembled into a boardId => true map, an empty (ACL-resolved) set
 * short-circuits without a query, and a board that produced no row (the user
 * has not pinned it, OR it is outside the fed readable set) is absent from the
 * map (defaults to not-pinned).
 */
class BoardPinMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private BoardPinMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new BoardPinMapper($this->db);
	}

	/**
	 * A stand-in for the expression builders (see CardReviewMapperTest): any
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
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$sink = self::exprSink();
		$qb->method('expr')->willReturn($sink);
		$qb->method('createNamedParameter')->willReturn('?');

		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(static function () use (&$queue) {
			$row = array_shift($queue);
			return $row ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	public function testPinnedMapMarksOnlyPinnedBoards(): void {
		// Board 9 is in the requested set but yields no row - it must be absent.
		$this->stubQuery([
			['board_id' => 7],
			['board_id' => 11],
		]);

		$map = $this->mapper->pinnedMap('alice', [7, 9, 11]);
		self::assertSame([7 => true, 11 => true], $map);
		self::assertArrayNotHasKey(9, $map);
	}

	public function testPinnedMapEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->pinnedMap('alice', []));
	}

	public function testFindPinnedBoardIdsCollectsIds(): void {
		$this->stubQuery([
			['board_id' => 3],
			['board_id' => 8],
		]);

		self::assertSame([3, 8], $this->mapper->findPinnedBoardIds('alice'));
	}
}

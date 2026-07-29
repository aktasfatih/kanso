<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\CardReviewMapper;
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
		$this->mapper = new CardReviewMapper($this->db);
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

		self::assertSame([7 => 3, 9 => 1], $this->mapper->needsReviewCountByBoards([7, 9]));
	}

	public function testNeedsReviewCountByBoardsEmptySetShortCircuits(): void {
		$this->db->expects(self::never())->method('getQueryBuilder');

		self::assertSame([], $this->mapper->needsReviewCountByBoards([]));
	}

	public function testNeedsReviewCountByBoardsOmitsBoardsWithNoOpenReviews(): void {
		// Board 9 is in the requested set but has no not-approved reviews - it
		// yields no grouped row and must be absent from the map (defaults to 0).
		$this->stubQuery([['board_id' => 7, 'cnt' => 2]]);

		$map = $this->mapper->needsReviewCountByBoards([7, 9]);
		self::assertSame([7 => 2], $map);
		self::assertArrayNotHasKey(9, $map);
	}
}

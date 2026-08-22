<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\ChangeDetailMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mapper-level tests for the change-detail side table. The DB is mocked so these
 * verify the pure-PHP contract: findByChangeIds short-circuits on empty input,
 * and otherwise hydrates the rows into a map keyed by change id.
 */
class ChangeDetailMapperTest extends TestCase {
	private IDBConnection&MockObject $db;
	private ChangeDetailMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new ChangeDetailMapper($this->db);
	}

	private static function exprSink(): object {
		return new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		};
	}

	/**
	 * A fluent query-builder mock whose executeQuery() returns a result iterating
	 * $rows once (for findEntities).
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function buildQb(array $rows): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where'] as $method) {
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

	public function testFindByChangeIdsEmptyInputShortCircuits(): void {
		// No query is built for an empty id set.
		$this->db->expects(self::never())->method('getQueryBuilder');
		self::assertSame([], $this->mapper->findByChangeIds([]));
	}

	public function testFindByChangeIdsMapsRowsByChangeId(): void {
		$this->db->method('getQueryBuilder')->willReturn($this->buildQb([
			['id' => 1, 'change_id' => 55, 'from_text' => 'Old', 'to_text' => 'New'],
			['id' => 2, 'change_id' => 60, 'from_text' => null, 'to_text' => 'Added'],
		]));

		$map = $this->mapper->findByChangeIds([55, 60]);

		self::assertArrayHasKey(55, $map);
		self::assertArrayHasKey(60, $map);
		self::assertSame('Old', $map[55]->getFromText());
		self::assertSame('New', $map[55]->getToText());
		self::assertNull($map[60]->getFromText());
		self::assertSame('Added', $map[60]->getToText());
	}
}

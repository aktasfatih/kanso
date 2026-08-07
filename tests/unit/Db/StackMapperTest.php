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
}

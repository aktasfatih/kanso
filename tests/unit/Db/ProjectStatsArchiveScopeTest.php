<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProjectStatsArchiveScopeTest extends TestCase {
	public static function aggregates(): array {
		return [
			'priority' => [CardMapper::class, 'countByPriorityForCards', 'id', []],
			'aging' => [CardMapper::class, 'agingCountForCards', 'id', [1000]],
			'overdue' => [CardMapper::class, 'overdueCountForCards', 'id', [new \DateTime('@1000')]],
			'completed' => [CardMapper::class, 'doneTimelineForCards', 'id', [0, 1000]],
			'created' => [CardMapper::class, 'createdTimelineForCards', 'id', [0, 1000]],
			'cycle time' => [CardMapper::class, 'doneCycleTimesForCards', 'id', [0, 1000]],
			'assignees' => [CardAssigneeMapper::class, 'countByAssigneeForCards', 'c.id', []],
			'labels' => [CardLabelMapper::class, 'countByLabelForCards', 'c.id', []],
		];
	}

	#[DataProvider('aggregates')]
	public function testProjectAggregatesKeepArchivedCardsAndRemainScoped(string $mapperClass, string $method, string $idColumn, array $args): void {
		$comparisons = [];
		$expr = new class($comparisons) {
			public function __construct(
				private array &$comparisons,
			) {
			}

			public function __call(string $method, array $args): string {
				$this->comparisons[] = [$method, $args[0] ?? null, $args[1] ?? null];
				return '';
			}
		};
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'from', 'innerJoin', 'where', 'andWhere', 'groupBy', 'addGroupBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn(new class {
			public function __call(string $method, array $args): string {
				return '';
			}
		});
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->method('fetchAll')->willReturn([]);
		$result->method('fetchOne')->willReturn(0);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new $mapperClass($db, new CardVisibilityScope());
		$mapper->$method([7, 9], ...$args);

		self::assertContains(['in', $idColumn, [7, 9]], $comparisons);
		self::assertContains(['eq', $idColumn === 'id' ? 'deleted_at' : 'c.deleted_at', 0], $comparisons);
		foreach ($comparisons as [, $column]) {
			self::assertNotContains($column, ['archived', 'c.archived']);
		}
	}
}

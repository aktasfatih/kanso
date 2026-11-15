<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ProjectAccess;
use OCA\Kanso\Db\BoardGroupMapper;
use OCA\Kanso\Db\BoardGroupMemberMapper;
use OCA\Kanso\Db\BoardPinMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserCleanupMapperTest extends TestCase {
	/** @return array<string, array{class-string, string, int}> */
	public static function personalMappers(): array {
		return [
			'board pins' => [BoardPinMapper::class, 'kanso_board_pins', 2],
			'folder members' => [BoardGroupMemberMapper::class, 'kanso_board_group_members', 3],
			'folders' => [BoardGroupMapper::class, 'kanso_board_groups', 1],
			'no rows' => [BoardGroupMemberMapper::class, 'kanso_board_group_members', 0],
		];
	}

	private function expression(string $column): object {
		return new class($column) {
			public function __construct(
				private string $column,
			) {
			}

			public function eq(string $column, string $parameter): string {
				TestCase::assertSame($this->column, $column);
				TestCase::assertSame(':uid', $parameter);
				return 'scoped';
			}
		};
	}

	#[DataProvider('personalMappers')]
	public function testPersonalCleanupBindsOnlyTheDeletedUid(string $class, string $table, int $deleted): void {
		$uid = "deleted' OR 1=1 --";
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects(self::once())->method('delete')->with($table)->willReturnSelf();
		$qb->expects(self::once())->method('createNamedParameter')->with($uid)->willReturn(':uid');
		$qb->method('expr')->willReturn($this->expression('uid'));
		$qb->expects(self::once())->method('where')->with('scoped')->willReturnSelf();
		$qb->expects(self::once())->method('executeStatement')->willReturn($deleted);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('getQueryBuilder')->willReturn($qb);

		self::assertSame($deleted, (new $class($db))->deleteByUser($uid));
	}

	public function testLeadCleanupSetsOnlyMatchingLeadsToSqlNull(): void {
		$uid = 'deleted-user';
		$bindings = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects(self::once())->method('update')->with('kanso_projects')->willReturnSelf();
		$qb->expects(self::exactly(2))->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type = IQueryBuilder::PARAM_STR) use (&$bindings): string {
				$bindings[] = [$value, $type];
				return $value === null ? ':null' : ':uid';
			}
		);
		$qb->expects(self::once())->method('set')->with('lead', ':null')->willReturnSelf();
		$qb->method('expr')->willReturn($this->expression('lead'));
		$qb->expects(self::once())->method('where')->with('scoped')->willReturnSelf();
		$qb->expects(self::once())->method('executeStatement')->willReturn(2);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('getQueryBuilder')->willReturn($qb);
		$mapper = new ProjectMapper($db, $this->createMock(ProjectAccess::class));

		self::assertSame(2, $mapper->clearLeadByUser($uid));
		self::assertSame([[null, IQueryBuilder::PARAM_NULL], [$uid, IQueryBuilder::PARAM_STR]], $bindings);
	}
}

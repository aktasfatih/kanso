<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\CardRunningTimerMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class CardRunningTimerMapperTest extends TestCase {
	public function testDeleteIfCurrentCannotDeleteAReplacementTimer(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects(self::once())->method('delete')->with('kanso_card_running_timers')->willReturnSelf();
		$qb->expects(self::once())->method('where')->with('eq(id,3)')->willReturnSelf();
		$qb->expects(self::once())->method('andWhere')->with('eq(card_id,9)')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(static fn (int $value): string => (string)$value);
		$qb->method('expr')->willReturn(new class {
			public function eq(string $column, string $value): string {
				return 'eq(' . $column . ',' . $value . ')';
			}
		});
		$qb->method('executeStatement')->willReturn(0);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		self::assertSame(0, (new CardRunningTimerMapper($db))->deleteIfCurrent(3, 9));
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\CardFileLinkMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class CardFileLinkMapperTest extends TestCase {
	public function testMoveReadIncludesLinksBeyondNormalCardLimit(): void {
		$rows = [];
		for ($i = 1; $i <= CardFileLinkMapper::MAX_PER_CARD + 1; $i++) {
			$rows[] = ['id' => $i, 'card_id' => 9, 'file_id' => $i];
		}
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->expects(self::never())->method('setMaxResults');
		$qb->method('expr')->willReturn(new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		});
		$qb->method('createNamedParameter')->with(9, IQueryBuilder::PARAM_INT)->willReturn('?');
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return array_shift($rows) ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$links = (new CardFileLinkMapper($db))->findAllByCardForMove(9);
		self::assertCount(CardFileLinkMapper::MAX_PER_CARD + 1, $links);
		self::assertSame(CardFileLinkMapper::MAX_PER_CARD + 1, $links[CardFileLinkMapper::MAX_PER_CARD]->getFileId());
	}
}

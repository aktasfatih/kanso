<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Db\CommentReactionMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class CommentReactionMapperTest extends TestCase {
	public function testMoveReadChunksCommentIdsAndPreservesReactionOrder(): void {
		$chunks = [];
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::exactly(2))->method('getQueryBuilder')->willReturnCallback(function () use (&$chunks): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'from', 'where', 'orderBy'] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$qb->method('expr')->willReturn(new class {
				public function __call(string $name, array $args): string {
					return '';
				}
			});
			$qb->method('createNamedParameter')->willReturnCallback(static function (array $ids, $type) use (&$chunks): string {
				self::assertSame(IQueryBuilder::PARAM_INT_ARRAY, $type);
				$chunks[] = $ids;
				return '?';
			});
			$result = $this->createMock(IResult::class);
			$rows = null;
			$result->method('fetch')->willReturnCallback(static function () use (&$chunks, &$rows) {
				if ($rows === null) {
					$ids = count($chunks) === 1 ? [4, 7] : [1, 5];
					$rows = array_map(static fn (int $id): array => ['id' => $id, 'comment_id' => 1, 'uid' => 'alice', 'emoji' => '👍', 'created_at' => 1], $ids);
					$rows[] = false;
				}
				return array_shift($rows);
			});
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});

		$ids = range(1, 501);
		$ids[] = 1;
		$reactions = (new CommentReactionMapper($db))->findEntitiesByComments($ids);
		self::assertSame([500, 1], array_map('count', $chunks));
		self::assertSame([1, 4, 5, 7], array_map(static fn ($reaction): int => $reaction->getId(), $reactions));
	}
}

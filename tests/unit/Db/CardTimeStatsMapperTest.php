<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class CardTimeStatsMapperTest extends TestCase {
	/** @var list<array{op: string, column: mixed, value: mixed}> */
	private array $predicates = [];
	/** @var list<string> */
	private array $groups = [];

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array{uid: string, actorName: ?string, seconds: int}>
	 */
	private function query(array $rows = []): array {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		foreach (['groupBy', 'addGroupBy'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $column) use ($qb): IQueryBuilder {
				$this->groups[] = $column;
				return $qb;
			});
		}
		$qb->method('expr')->willReturn(new class($this->predicates) {
			/** @param list<array{op: string, column: mixed, value: mixed}> $predicates */
			public function __construct(
				private array &$predicates,
			) {
			}

			public function __call(string $name, array $args): string {
				$this->predicates[] = ['op' => $name, 'column' => $args[0] ?? null, 'value' => $args[1] ?? null];
				return '';
			}
		});
		$qb->method('func')->willReturn(new class {
			public function __call(string $name, array $args): string {
				return 'sum';
			}
		});
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
			return array_shift($rows) ?? false;
		});
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$mapper = new CardMapper($db, new CardVisibilityScope());
		return $mapper->timeSpentByUser(7, 100, 200, ViewerContext::forMember('alice', 7, ViewerContext::ROLE_EXTERNAL, false));
	}

	/** @return list<mixed> */
	private function values(string $op, string $column): array {
		return array_values(array_map(
			static fn (array $predicate): mixed => $predicate['value'],
			array_filter($this->predicates, static fn (array $predicate): bool => $predicate['op'] === $op && $predicate['column'] === $column),
		));
	}

	public function testLimitsBothTablesAndTheEntireDateWindow(): void {
		$this->query();

		self::assertSame([7], $this->values('eq', 'te.board_id'));
		self::assertSame([7, 7], $this->values('eq', 'c.board_id'));
		self::assertSame([0], $this->values('eq', 'c.deleted_at'));
		self::assertSame([false], $this->values('eq', 'c.is_template'));
		self::assertSame([100], $this->values('gte', 'te.created_at'));
		self::assertSame([200], $this->values('lt', 'te.created_at'));
		self::assertSame([], $this->values('eq', 'c.archived'));
	}

	public function testAppliesTheResolvedViewersRoleAndPrivateCardBoundary(): void {
		$this->query();

		self::assertSame(['public', 'internal', 'private'], $this->values('eq', 'c.visibility'));
		self::assertSame([ViewerContext::ROLE_EXTERNAL], $this->values('eq', 'c.creator_role'));
		self::assertSame(['alice'], $this->values('eq', 'c.owner'));
	}

	public function testKeepsHumanAndAiActorsSeparateWithinEachOwningUser(): void {
		$rows = $this->query([
			['created_by' => 'alice', 'actor_name' => null, 'total_seconds' => '3600'],
			['created_by' => 'alice', 'actor_name' => 'Codex', 'total_seconds' => '1800'],
			['created_by' => 'bob', 'actor_name' => 'Codex', 'total_seconds' => '900'],
		]);

		self::assertSame(['te.created_by', 'te.actor_name'], $this->groups);
		self::assertSame([
			['uid' => 'alice', 'actorName' => null, 'seconds' => 3600],
			['uid' => 'alice', 'actorName' => 'Codex', 'seconds' => 1800],
			['uid' => 'bob', 'actorName' => 'Codex', 'seconds' => 900],
		], $rows);
	}
}

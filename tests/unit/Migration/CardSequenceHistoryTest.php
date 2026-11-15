<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Migration;

use OCA\Kanso\Migration\Version007600Date20261112000000;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class CardSequenceHistoryTest extends TestCase {
	public function testMissingPrefixFailsBeforeReadingHistory(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('executeQuery');
		$this->expectException(\InvalidArgumentException::class);
		(new Version007600Date20261112000000($db))->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	private function dbResult(mixed $scalar = null, array|false $row = false): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($scalar);
		$result->method('fetch')->willReturn($row);
		return $result;
	}

	/** @return list<array{0: string, 1: array}> */
	private function migrate(string $provider, callable $read): array {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($provider);
		$db->method('executeQuery')->willReturnCallback($read);
		$writes = [];
		$db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$writes): int {
			$writes[] = [$sql, $params];
			return 1;
		});
		$this->backfill($db);
		(new Version007600Date20261112000000($db))->postSchemaChange($this->createMock(IOutput::class), static fn () => null, ['tablePrefix' => 'custom_']);
		return $writes;
	}

	private function backfill(IDBConnection $db): void {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'innerJoin', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn(new class {
			public function __call(string $name, array $args): string {
				return '';
			}
		});
		$qb->method('executeQuery')->willReturn($this->dbResult());
		$db->method('getQueryBuilder')->willReturn($qb);
	}

	public function testRetainedAliasesAndSequencesRaiseTheConservativeFloor(): void {
		$writes = $this->migrate(IDBConnection::PLATFORM_SQLITE, function (string $sql, array $params = []): IResult {
			if (str_contains($sql, 'sqlite_sequence')) {
				self::assertSame(['custom_kanso_cards'], $params);
				return $this->dbResult(31);
			}
			return $this->dbResult(row: str_contains($sql, 'move_aliases')
				? ['old_id' => 50, 'target_id' => 60, 'board_seq' => 80]
				: ['card_id' => 40, 'board_seq' => 70]);
		});
		self::assertSame([80], $writes[0][1]);
	}

	public function testPostgresUsesTheDiscoveredIdentitySequence(): void {
		$writes = $this->migrate(IDBConnection::PLATFORM_POSTGRES, function (string $sql, array $params = []): IResult {
			if (str_contains($sql, 'pg_get_serial_sequence')) {
				self::assertSame(['custom_kanso_cards', 'custom_kanso_cards'], $params);
				return $this->dbResult(row: ['sequence_name' => 'public.custom_kanso_cards_id_seq', 'last_value' => 90]);
			}
			return $this->dbResult(row: ['card_id' => null, 'board_seq' => null]);
		});
		self::assertSame([90], $writes[0][1]);
	}

	public function testMissingPostgresIdentityFailsBeforeSeeding(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Card identity sequence is unavailable');
		$this->migrate(IDBConnection::PLATFORM_POSTGRES, fn (): IResult => $this->dbResult(row: ['sequence_name' => null, 'last_value' => null]));
	}

	public function testMysqlBypassesCachedStatisticsAndRestoresTheSession(): void {
		$writes = $this->migrate(IDBConnection::PLATFORM_MYSQL, function (string $sql, array $params = []): IResult {
			if ($sql === 'SELECT VERSION()') {
				return $this->dbResult('8.4.7');
			}
			if (str_contains($sql, '@@SESSION')) {
				return $this->dbResult(86400);
			}
			if (str_contains($sql, 'information_schema.TABLES')) {
				self::assertSame(['custom_kanso_cards'], $params);
				return $this->dbResult(101);
			}
			return $this->dbResult(row: ['card_id' => null, 'board_seq' => null]);
		});
		self::assertSame(['SET SESSION information_schema_stats_expiry = 0', []], $writes[0]);
		self::assertSame(['SET SESSION information_schema_stats_expiry = ?', [86400]], $writes[1]);
		self::assertSame([100], $writes[2][1]);
	}

	public function testMysqlRestoresTheSessionWhenMetadataCannotBeRead(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		$db->method('executeQuery')->willReturnCallback(function (string $sql): IResult {
			return match ($sql) {
				'SELECT VERSION()' => $this->dbResult('8.4.7'),
				'SELECT @@SESSION.information_schema_stats_expiry' => $this->dbResult(3600),
				default => throw new \RuntimeException('Metadata read failed'),
			};
		});
		$writes = [];
		$db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$writes): int {
			$writes[] = [$sql, $params];
			return 1;
		});
		try {
			(new Version007600Date20261112000000($db))->postSchemaChange($this->createMock(IOutput::class), static fn () => null, ['tablePrefix' => 'custom_']);
			self::fail('Missing metadata must fail the migration');
		} catch (\RuntimeException $e) {
			self::assertSame('Metadata read failed', $e->getMessage());
		}
		self::assertSame([
			['SET SESSION information_schema_stats_expiry = 0', []],
			['SET SESSION information_schema_stats_expiry = ?', [3600]],
		], $writes);
	}

	public function testExhaustedMariaDbIdentitySaturatesWithoutIntegerOverflow(): void {
		$writes = $this->migrate(IDBConnection::PLATFORM_MYSQL, function (string $sql): IResult {
			if ($sql === 'SELECT VERSION()') {
				return $this->dbResult('11.8.9-MariaDB');
			}
			if (str_contains($sql, 'information_schema.TABLES')) {
				return $this->dbResult('9223372036854775808');
			}
			return $this->dbResult(row: ['card_id' => null, 'board_seq' => null]);
		});
		self::assertCount(1, $writes);
		self::assertSame([PHP_INT_MAX], $writes[0][1]);
	}

	public function testInvalidHistoryFailsInsteadOfFallingBackToZero(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Invalid card number history');
		$this->migrate(IDBConnection::PLATFORM_SQLITE, fn (): IResult => $this->dbResult('-1'));
	}
}

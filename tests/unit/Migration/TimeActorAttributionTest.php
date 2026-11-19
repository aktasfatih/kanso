<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Migration;

use OCA\Kanso\Migration\Version007800Date20261118000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class TimeActorAttributionTest extends TestCase {
	public function testUpgradeAddsOptionalActorNamesAndCanRunAgain(): void {
		$tables = [];
		foreach (['kanso_card_time_entries', 'kanso_card_running_timers'] as $name) {
			$tables[$name] = new class {
				public array $columns = [];

				public function hasColumn(string $name): bool {
					return isset($this->columns[$name]);
				}

				public function addColumn(string $name, string $type, array $options): void {
					$this->columns[$name] = [$type, $options];
				}
			};
		}
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $name): bool => isset($tables[$name]));
		$schema->method('getTable')->willReturnCallback(static fn (string $name) => $tables[$name]);
		$output = $this->createMock(IOutput::class);
		$migration = new Version007800Date20261118000000();

		self::assertSame($schema, $migration->changeSchema($output, static fn () => $schema, []));
		foreach ($tables as $table) {
			self::assertSame(['actor_name' => [Types::STRING, ['notnull' => false, 'length' => 255]]], $table->columns);
		}
		self::assertNull($migration->changeSchema($output, static fn () => $schema, []));
	}
}

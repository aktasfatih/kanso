<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Migration;

use OCA\Kanso\Migration\Version007900Date20261119000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class ProjectArchivingTest extends TestCase {
	public function testUpgradeDefaultsExistingProjectsToActiveAndCanRunAgain(): void {
		$table = new class {
			public array $columns = [];

			public function hasColumn(string $name): bool {
				return isset($this->columns[$name]);
			}

			public function addColumn(string $name, string $type, array $options): void {
				$this->columns[$name] = [$type, $options];
			}
		};
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('kanso_projects')->willReturn(true);
		$schema->method('getTable')->with('kanso_projects')->willReturn($table);
		$output = $this->createMock(IOutput::class);
		$migration = new Version007900Date20261119000000();

		self::assertSame($schema, $migration->changeSchema($output, static fn () => $schema, []));
		self::assertSame(['archived' => [Types::BOOLEAN, ['notnull' => false, 'default' => false]]], $table->columns);
		self::assertNull($migration->changeSchema($output, static fn () => $schema, []));
	}
}

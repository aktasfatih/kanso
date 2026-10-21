<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version006700Date20261021000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('kanso_projects')) {
			$table = $schema->getTable('kanso_projects');
			// Calendar dates (YYYY-MM-DD), not instants: a deadline has no time zone.
			foreach (['start_date', 'target_date'] as $column) {
				if (!$table->hasColumn($column)) {
					$table->addColumn($column, Types::STRING, [
						'notnull' => false,
						'length' => 10,
					]);
					$changed = true;
				}
			}
		}

		if ($schema->hasTable('kanso_project_cards')) {
			$table = $schema->getTable('kanso_project_cards');
			if (!$table->hasColumn('added_at')) {
				// 0 = added before this column existed.
				$table->addColumn('added_at', Types::BIGINT, [
					'notnull' => true,
					'length' => 8,
					'default' => 0,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}

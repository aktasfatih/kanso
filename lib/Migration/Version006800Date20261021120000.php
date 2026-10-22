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

class Version006800Date20261021120000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_project_pins')) {
			return null;
		}

		$table = $schema->createTable('kanso_project_pins');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('project_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->setPrimaryKey(['id'], 'kanso_ppin_pk');
		// Leading uid column also serves the per-user listing.
		$table->addUniqueIndex(['uid', 'project_id'], 'kanso_ppin_unique');
		$table->addIndex(['project_id'], 'kanso_ppin_project');

		return $schema;
	}
}

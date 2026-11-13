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

class Version007500Date20261112000000 extends SimpleMigrationStep {
	/** @psalm-suppress UndefinedDocblockClass */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('kanso_projects');
		if (!$table->hasColumn('lead')) {
			$table->addColumn('lead', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}
		if (!$table->hasColumn('lifecycle_status')) {
			$table->addColumn('lifecycle_status', Types::STRING, [
				'notnull' => true,
				'default' => 'planned',
				'length' => 16,
			]);
		}

		return $schema;
	}
}

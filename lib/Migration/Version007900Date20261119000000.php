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

class Version007900Date20261119000000 extends SimpleMigrationStep {
	/** @psalm-suppress UndefinedDocblockClass */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('kanso_projects')) {
			return null;
		}
		$table = $schema->getTable('kanso_projects');
		if ($table->hasColumn('archived')) {
			return null;
		}
		$table->addColumn('archived', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		return $schema;
	}
}

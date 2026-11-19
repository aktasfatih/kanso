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

class Version008000Date20261119000000 extends SimpleMigrationStep {
	/** @psalm-suppress UndefinedDocblockClass */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('kanso_boards')) {
			return null;
		}
		$table = $schema->getTable('kanso_boards');
		if ($table->hasColumn('gitlab_webhook_secret')) {
			return null;
		}
		$table->addColumn('gitlab_webhook_secret', Types::STRING, [
			'notnull' => false,
			'length' => 128,
		]);
		return $schema;
	}
}

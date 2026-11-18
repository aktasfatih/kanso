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

class Version007800Date20261118000000 extends SimpleMigrationStep {
	/** @psalm-suppress UndefinedDocblockClass */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		foreach (['kanso_card_time_entries', 'kanso_card_running_timers'] as $name) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			if (!$table->hasColumn('actor_name')) {
				$table->addColumn('actor_name', Types::STRING, [
					'notnull' => false,
					'length' => 255,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}

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

class Version006900Date20261022000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('kanso_boards')) {
			$boards = $schema->getTable('kanso_boards');
			if (!$boards->hasColumn('estimate_custom')) {
				// JSON list of {label, value} for the `custom` estimate scale.
				$boards->addColumn('estimate_custom', Types::TEXT, [
					'notnull' => false,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('kanso_cards')) {
			$estimate = $schema->getTable('kanso_cards')->getColumn('estimate');
			// Room for custom-scale labels (up to 24 characters).
			if (($estimate->getLength() ?? 0) < 32) {
				$estimate->setLength(32);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}

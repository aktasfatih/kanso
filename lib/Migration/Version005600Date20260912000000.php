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

/**
 * Per-board built-in card feature switches (`kanso_boards.disabled_card_features`).
 *
 * A board manager can hide card sections the team never uses (contacts,
 * attachments, GitHub links, time tracking, cover colour). The column stores a
 * small JSON array of the DISABLED keys, e.g. `["attachments","github"]`.
 *
 * NULL (the default for every existing row) means "nothing disabled" - every
 * feature stays on - so this is purely additive with no backfill and no
 * behaviour change on upgrade. Storing only the disabled keys also means a
 * sixth feature can be added later without another migration.
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version005600Date20260912000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_boards')) {
			$table = $schema->getTable('kanso_boards');
			if (!$table->hasColumn('disabled_card_features')) {
				$table->addColumn('disabled_card_features', Types::TEXT, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}

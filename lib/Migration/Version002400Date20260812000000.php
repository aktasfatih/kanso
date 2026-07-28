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
 * Per-board "new cards on top" preference (`kanso_boards.new_cards_on_top`):
 * when set, a newly-created card gets a sort key ABOVE the column head instead
 * of appended at the bottom. Nullable/false by default so existing boards keep
 * the append behaviour. Purely additive - no backfill.
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version002400Date20260812000000 extends SimpleMigrationStep {
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
			if (!$table->hasColumn('new_cards_on_top')) {
				$table->addColumn('new_cards_on_top', Types::BOOLEAN, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}

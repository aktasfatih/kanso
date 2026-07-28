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
 * All-day due dates (`kanso_cards.all_day`): a nullable flag paired with the
 * existing `duedate`. When set, the card's due date is a date only (no
 * time-of-day) - the client hides the time picker and the "due at HH:MM"
 * display. `duedate` itself is unchanged (stored at 00:00), so timed dues keep
 * working. Purely additive and nullable - no backfill needed.
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version002200Date20260810000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_cards')) {
			$table = $schema->getTable('kanso_cards');
			if (!$table->hasColumn('all_day')) {
				$table->addColumn('all_day', Types::BOOLEAN, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}

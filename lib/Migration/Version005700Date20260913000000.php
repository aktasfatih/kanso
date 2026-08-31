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
 * A real revision token for card descriptions (`kanso_cards.description_revision`).
 *
 * A per-card counter, bumped by every write that CHANGES the description. It
 * replaces `last_modified` as the optimistic-concurrency base version: unlike a
 * unix-SECOND timestamp it has no resolution gap (a competing write inside the
 * same second is no longer indistinguishable), and it moves ONLY for description
 * writes, so an unrelated title/date save can never over-report a conflict.
 * Being an integer it is also comparable in SQL on SQLite, MySQL and
 * PostgreSQL alike, which is what lets the guard become a single conditional
 * UPDATE ({@see \OCA\Kanso\Db\CardMapper::claimDescriptionRevision()}) instead
 * of a read-then-write check two requests can both pass.
 *
 * Every existing row starts at 0 (the column default) - exactly what a client
 * seeded from a fresh read sends back - so this is purely additive, with no
 * backfill and no behaviour change on upgrade.
 *
 * Guarded (hasTable/hasColumn) so the step is idempotent.
 */
class Version005700Date20260913000000 extends SimpleMigrationStep {
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
			if (!$table->hasColumn('description_revision')) {
				$table->addColumn('description_revision', Types::INTEGER, [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		return $schema;
	}
}

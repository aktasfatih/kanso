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
 * Side table `kanso_change_details` holding the before/after text of a change
 * (the description edit's from/to, surfaced as a diff in the per-card Activity
 * feed).
 *
 * Deliberately SEPARATE from `kanso_changes`: that log is the hot
 * delta-sync/ETag table (every board poll reads it), so the wide, nullable TEXT
 * payload lives here instead, joined by `change_id` only when the Activity feed
 * needs it. No hard FK (NC convention) - the `kanso_chdet_change_idx` index on
 * `change_id` backs the batch lookup ChangeDetailMapper::findByChangeIds() runs.
 *
 * Guarded (hasTable/hasColumn/hasIndex) so the step is idempotent.
 */
class Version005400Date20260910000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable()/getTable()
	 *  are docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_change_details')) {
			$table = $schema->createTable('kanso_change_details');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('change_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('from_text', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('to_text', Types::TEXT, [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id'], 'kanso_chdet_pk');
			$table->addIndex(['change_id'], 'kanso_chdet_change_idx');
		}

		return $schema;
	}
}

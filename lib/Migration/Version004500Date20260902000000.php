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
 * Custom fields on cards (#3537). Two tables, modelled on `kanso_review_types`:
 *
 * - `kanso_card_fields`: per-board custom-field DEFINITIONS. A small fixed set
 *   of typed fields (text / number / date / select) defined per board. `type`
 *   is an app-level enum stored as a plain string (validated in the service,
 *   NOT a DB enum); `options` is a JSON array only meaningful for `select`.
 *   Ordered by a FRACTIONAL `sort_key` (reorder is a single-row UPDATE), never
 *   by id.
 * - `kanso_card_field_values`: the per-card VALUES. One value per
 *   (card_id, field_id) - a unique index enforces it, so a set is an upsert.
 *   The value is a single stringified column (per-type coercion is the
 *   service's job, not a column-per-type).
 *
 * Both tables are hasTable-guarded so the step is idempotent; index / unique
 * names are globally unique.
 */
class Version004500Date20260902000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable()
	 *  is docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_card_fields')) {
			$table = $schema->createTable('kanso_card_fields');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('options', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('sort_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// Named short defensively: oc_kanso_card_fields (20 chars) sits at the
			// edge of the default primary-key name length limit enforced on NC
			// 30-32; an explicit short name keeps install safe across versions.
			$table->setPrimaryKey(['id'], 'kanso_cfield_pk');
			$table->addIndex(['board_id'], 'kanso_card_fields_board');
		}

		if (!$schema->hasTable('kanso_card_field_values')) {
			$table = $schema->createTable('kanso_card_field_values');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('card_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('field_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('value', Types::TEXT, [
				'notnull' => false,
			]);
			// Named short: oc_kanso_card_field_values (26 chars) overflows the
			// default primary-key name length check, failing install on NC 30-32
			// (the install matrix surfaced: 'Primary index name on
			// "oc_kanso_card_field_values" is too long.').
			$table->setPrimaryKey(['id'], 'kanso_cfieldval_pk');
			// One value per field per card - a set is an upsert.
			$table->addUniqueIndex(['card_id', 'field_id'], 'kanso_card_field_val_uniq');
			$table->addIndex(['field_id'], 'kanso_card_field_val_field');
		}

		return $schema;
	}
}

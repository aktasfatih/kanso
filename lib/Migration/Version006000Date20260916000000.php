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
 * Deck-import bookkeeping (#10300): one row per (Deck board, importing user)
 * recording which Kanso board a Deck board was imported into, and when.
 *
 * Without it the importer had no memory: a re-submitted import - the classic
 * double-submit, where the first request actually succeeded but its response was
 * lost - silently produced a second complete board AND a second physical copy of
 * every attachment's bytes.
 *
 * The key is (deck_board_id, imported_by), NOT the board title and NOT the Deck
 * board id alone:
 * - titles are not unique and users rename boards, so a title key would refuse
 *   legitimate imports;
 * - a global key on deck_board_id would refuse a *different* user importing a
 *   board shared with them, which is exactly what happens mid-migration.
 *
 * The NAMED unique index is the guard itself, not just an optimisation: the
 * mapping row is inserted inside the import's own transaction, so a concurrent
 * duplicate submit loses the race at the database and rolls the whole import
 * back (no board, no bytes). A deliberate re-import is still allowed - the
 * service deletes the old mapping row first, behind an explicit confirmation.
 *
 * Guarded by hasTable so the step is idempotent and safe on a fresh install.
 *
 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
 *  stubs (Deck suppresses the same class in its psalm config).
 */
class Version006000Date20260916000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_deck_imports')) {
			return null;
		}

		$table = $schema->createTable('kanso_deck_imports');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('deck_board_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('kanso_board_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('imported_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('imported_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// Named explicitly: `oc_kanso_deck_imports` is long enough that the
		// auto-derived primary-key name would overflow NC 30-32's identifier
		// limit and fail the install outright.
		$table->setPrimaryKey(['id'], 'kanso_dimp_pk');
		// One import record per (Deck board, importing user) - the idempotency
		// guarantee, enforced in the schema so a concurrent double-submit cannot
		// slip between a check and a write.
		// This also backs every read: the pre-check, the release-before-re-import
		// and the picker's LEFT JOIN all key on the full pair, so there is no
		// second index to maintain on every write.
		$table->addUniqueIndex(['deck_board_id', 'imported_by'], 'kanso_dimp_unique');

		return $schema;
	}
}

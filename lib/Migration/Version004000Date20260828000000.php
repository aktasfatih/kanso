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
 * Per-user board pinning (#3632): a pin is one row per (user, board) in
 * `kanso_board_pins`. Pinning is the curation mechanism that drives BOTH the
 * boards-page "Pinned" section AND the left-sidebar nav. A user pins a board at
 * most once - enforced by a NAMED unique index on (uid, board_id). A named
 * index on uid backs the per-user pin listing.
 *
 * The `pinned` flag on the board-list payload is surfaced by ONE batched
 * `WHERE uid = ? AND board_id IN (...)` over the caller's readable board set
 * (never one query per board), mirroring the per-user board-folder groupId
 * lookup (#3529).
 *
 * Guarded by hasTable so the step is idempotent and safe on a fresh install.
 *
 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
 *  stubs (Deck suppresses the same class in its psalm config).
 */
class Version004000Date20260828000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_board_pins')) {
			return null;
		}

		$table = $schema->createTable('kanso_board_pins');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('board_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->setPrimaryKey(['id']);
		// A user pins a board at most once - the toggle's idempotency guarantee,
		// enforced in the schema (BoardPinMapper::pin swallows the violation).
		$table->addUniqueIndex(['uid', 'board_id'], 'kanso_bpin_unique');
		// The per-user pin listing reads by uid.
		$table->addIndex(['uid'], 'kanso_bpin_uid');

		return $schema;
	}
}

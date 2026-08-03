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
 * Per-user board grouping / folders in the nav (#3529).
 *
 * FLAT, one-level, PER-USER folders: my folders are invisible to you, so this
 * can never live on the shared `kanso_boards` row - it mirrors the per-user
 * `kanso_board_subscriptions` model. This is nav organization ONLY and is
 * distinct from Projects (which are cross-board CARD collections).
 *
 * Two tables:
 *  - `kanso_board_groups`         - the folder definitions (id, uid, name, sort).
 *  - `kanso_board_group_members`  - which board sits in which folder, per user.
 *    A board is in at most one folder per user (unique on (uid, board_id));
 *    an absent row = the board is Ungrouped. `group_id` also carries `uid` so
 *    every membership query stays scoped without a join back to the group.
 *
 * Both guarded (hasTable) so the step is idempotent.
 */
class Version003700Date20260825000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs
	 *  (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_board_groups')) {
			$table = $schema->createTable('kanso_board_groups');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('uid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('sort', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			// Short explicit PK name - a default-named PK is rejected once the
			// prefixed table name reaches Oracle's constraint-length limit.
			$table->setPrimaryKey(['id'], 'kanso_bgroup_pk');
			// The one hot lookup: every folder of a user, in sort order.
			$table->addIndex(['uid'], 'kanso_bgroup_uid');
		}

		if (!$schema->hasTable('kanso_board_group_members')) {
			$table = $schema->createTable('kanso_board_group_members');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('uid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('group_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->setPrimaryKey(['id'], 'kanso_bgmember_pk');
			// A board sits in at most one folder per user; the assign path relies
			// on this to make a re-assign an upsert and a double-assign idempotent.
			$table->addUniqueIndex(['uid', 'board_id'], 'kanso_bgmember_uniq');
			// Batched board-list lookup: all memberships of a user for a board set.
			$table->addIndex(['uid'], 'kanso_bgmember_uid');
			// Cascade lookup: all members of a folder (delete-folder ungroups them).
			$table->addIndex(['group_id'], 'kanso_bgmember_group');
		}

		return $schema;
	}
}

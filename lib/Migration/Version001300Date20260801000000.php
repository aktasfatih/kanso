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
 * Board subscriptions (`kanso_board_subscriptions`): a user watches a whole
 * board to get a "something new to look at" signal. Deliberately a separate
 * table from `kanso_subscriptions` (which is card-keyed and cascade-deleted per
 * card) - a board watch is presence-only: a row means subscribed, no row means
 * not. There is no auto-subscribe to a board, so no opt-out tombstone is needed.
 *
 * Guarded (hasTable) so the step is idempotent.
 */
class Version001300Date20260801000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs
	 *  (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_board_subscriptions')) {
			$table = $schema->createTable('kanso_board_subscriptions');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('subscriber', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			// One row per (subscriber, board); the subscribe path relies on this
			// to make a double-subscribe idempotent.
			$table->addUniqueIndex(['subscriber', 'board_id'], 'kanso_boardsub_uniq');
			// Fan-out lookup: all watchers of a board.
			$table->addIndex(['board_id'], 'kanso_boardsub_board');
		}

		return $schema;
	}
}

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
 * Manual time tracking on cards (`kanso_card_time_entries`, #3536). A row logs
 * a duration (in SECONDS) an actor spent on a card, with an optional note; the
 * per-card total is the SUM of these rows (surfaced only in the card DETAIL
 * payload, never the board/summary listings).
 *
 * MANUAL entries only - there is deliberately NO running-timer state (no
 * started_at/stopped_at/is_running). `board_id` is denormalized from the card
 * at insert time (set server-side) purely so board-permission gating and the
 * purge cascade don't need a card join; `card_id` remains the scoping key.
 *
 * Index/constraint names are globally unique (kanso_ctime_*) so this step is
 * safe on a fresh install. Guarded by hasTable so the step is idempotent.
 */
class Version004400Date20260901000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_card_time_entries')) {
			return null;
		}

		$table = $schema->createTable('kanso_card_time_entries');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// Denormalized from the card at insert time (set server-side) for gating
		// and the purge cascade without a card join.
		$table->addColumn('board_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// The logged duration, stored in SECONDS (not minutes).
		$table->addColumn('seconds', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// Optional free-text note describing the logged time.
		$table->addColumn('note', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('created_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id'], 'kanso_ctime_pk');
		// A card's entries (list + total) - the hot path.
		$table->addIndex(['card_id'], 'kanso_ctime_card');
		// Board-scoped queries / cascade on board delete.
		$table->addIndex(['board_id'], 'kanso_ctime_board');

		return $schema;
	}
}

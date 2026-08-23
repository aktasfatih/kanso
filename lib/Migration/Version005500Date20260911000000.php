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
 * Running timer state for cards (`kanso_card_running_timers`, #73). The manual
 * time-tracking table (`kanso_card_time_entries`) stores only FINISHED
 * durations; it has no notion of a clock that is currently running. A row here
 * is that minimal running state: while it exists the card's timer is "on",
 * started at `started_at`. When stopped, the elapsed seconds are written as a
 * finished time-entry and this row is dropped.
 *
 * At most ONE running timer per card (UNIQUE on card_id). `board_id` is
 * denormalized from the card at insert time (set server-side) so the board-purge
 * cascade doesn't need a card join.
 *
 * Index/constraint names are globally unique (kanso_crt_*) so this step is safe
 * on a fresh install. Guarded by hasTable so the step is idempotent.
 */
class Version005500Date20260911000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_card_running_timers')) {
			return null;
		}

		$table = $schema->createTable('kanso_card_running_timers');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// Denormalized from the card at insert time (set server-side) for the
		// board-purge cascade without a card join.
		$table->addColumn('board_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('started_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		// When the timer started, unix seconds.
		$table->addColumn('started_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id'], 'kanso_crt_pk');
		// At most one running timer per card.
		$table->addUniqueIndex(['card_id'], 'kanso_crt_card');
		// Board-scoped cascade on board purge.
		$table->addIndex(['board_id'], 'kanso_crt_board');

		return $schema;
	}
}

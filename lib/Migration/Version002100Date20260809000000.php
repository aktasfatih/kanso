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
 * Card relations (#3404): `kanso_card_relations` links two cards on the same
 * board. `type` is one of blocks | duplicates | relates. `blocks` is directional
 * (row A→B means "A blocks B"; "B blocked-by A" is the same row read backwards);
 * duplicates/relates are symmetric and stored once in a canonical order
 * (card_id < other_card_id). See {@see \OCA\Kanso\Db\CardRelation}.
 *
 * A unique (card_id, other_card_id, type) index makes a relation idempotent;
 * board_id is denormalised for the board-scoped "blocked" badge query and the
 * blocks cycle check. Guarded by hasTable (idempotent).
 */
class Version002100Date20260809000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_card_relations')) {
			$table = $schema->createTable('kanso_card_relations');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
			$table->addColumn('card_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
			$table->addColumn('other_card_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 8]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['card_id', 'other_card_id', 'type'], 'kanso_cardrel_uniq');
			$table->addIndex(['board_id', 'type'], 'kanso_cardrel_board_type');
			$table->addIndex(['other_card_id'], 'kanso_cardrel_other');
		}

		return $schema;
	}
}

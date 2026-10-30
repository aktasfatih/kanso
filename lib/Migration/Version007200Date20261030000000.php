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

class Version007200Date20261030000000 extends SimpleMigrationStep {
	/** @psalm-suppress UndefinedDocblockClass */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('kanso_card_move_aliases')) {
			return null;
		}
		$table = $schema->createTable('kanso_card_move_aliases');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
		$table->addColumn('old_card_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
		$table->addColumn('target_card_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
		$table->setPrimaryKey(['id'], 'kanso_cmove_alias_pk');
		$table->addUniqueIndex(['old_card_id'], 'kanso_cmove_alias_old');
		return $schema;
	}
}

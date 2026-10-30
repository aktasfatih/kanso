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

class Version007100Date20261029000000 extends SimpleMigrationStep {
	/** @psalm-suppress UndefinedDocblockClass */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('kanso_card_history')) {
			return null;
		}
		$table = $schema->createTable('kanso_card_history');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
		$table->addColumn('card_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
		$table->addColumn('action', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('verb', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
		$table->addColumn('has_detail', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('from_text', Types::TEXT, ['notnull' => false]);
		$table->addColumn('to_text', Types::TEXT, ['notnull' => false]);
		$table->setPrimaryKey(['id'], 'kanso_chist_pk');
		$table->addIndex(['card_id', 'id'], 'kanso_chist_card');
		return $schema;
	}
}

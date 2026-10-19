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

class Version006600Date20261018000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_card_file_links')) {
			return null;
		}

		$table = $schema->createTable('kanso_card_file_links');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('file_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('mime', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		$table->addColumn('linked_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// Named explicitly: the default PK name exceeds the NC length limit.
		$table->setPrimaryKey(['id'], 'kanso_cfl_pk');
		// Also serves per-card reads, so no separate card_id index.
		$table->addUniqueIndex(['card_id', 'file_id'], 'kanso_cfl_uniq');

		return $schema;
	}
}

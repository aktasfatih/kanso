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

class Version007400Date20261104000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_project_shares')) {
			return null;
		}

		$table = $schema->createTable('kanso_project_shares');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('project_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('participant_type', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('participant', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('permission', Types::INTEGER, [
			'notnull' => true,
			'default' => 1,
		]);
		$table->setPrimaryKey(['id'], 'kanso_pshare_pk');
		$table->addUniqueIndex(['project_id', 'participant_type', 'participant'], 'kanso_pshare_unique');
		$table->addIndex(['participant_type', 'participant'], 'kanso_pshare_participant');

		return $schema;
	}
}

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
 * Auto-archive rules (`kanso_archive_rules`): board-hygiene automation that
 * archives done cards once they cross an age threshold. A rule targets a
 * whole board (`stack_id` null) or a single stack, and picks one of two
 * conditions (see {@see \OCA\Kanso\Db\ArchiveRule} CONDITION_* constants):
 * done for at least N seconds, or done AND created at least N seconds ago.
 * The sweep is driven both by the {@see \OCA\Kanso\Cron\ArchiveDoneCards}
 * cron and the manual archive-now endpoint.
 *
 * Guarded by hasTable so the step is idempotent.
 */
class Version000300Date20260723000001 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_archive_rules')) {
			return null;
		}

		$table = $schema->createTable('kanso_archive_rules');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('board_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// null = whole board, else a single stack on that board.
		$table->addColumn('stack_id', Types::BIGINT, [
			'notnull' => false,
			'length' => 8,
		]);
		// 0 = done for >= threshold seconds; 1 = done AND created >= threshold
		// seconds ago. See ArchiveRule CONDITION_* constants.
		$table->addColumn('condition', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('threshold_seconds', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'unsigned' => true,
			'default' => 0,
		]);
		$table->addColumn('enabled', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'unsigned' => true,
			'default' => 0,
		]);
		// Named short: oc_kanso_archive_rules (22 chars) overflows the default
		// primary-key name length check, failing install on NC 30-32.
		$table->setPrimaryKey(['id'], 'kanso_archrule_pk');
		$table->addIndex(['board_id'], 'kanso_arch_rules_board');

		return $schema;
	}
}

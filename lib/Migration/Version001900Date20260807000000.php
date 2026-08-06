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
 * Per-board automation rules (`kanso_automation_rules`): a deliberately small,
 * FIXED trigger→action menu (no scripting, no DSL - the charter's Jira trap).
 * v1: trigger `card_entered_role` (a card moved into a stack with role R) →
 * action `request_review` (from a reviewer) or `add_label`. `params` is a small
 * JSON blob ({role, reviewer|label}). Enabled by default. Guarded (hasTable).
 */
class Version001900Date20260807000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_automation_rules')) {
			$table = $schema->createTable('kanso_automation_rules');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
			$table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
			$table->addColumn('trigger', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('params', Types::TEXT, ['notnull' => true, 'default' => '{}']);
			// Nextcloud's schema guard forbids a NOT NULL boolean (a false value
			// can read as null on some backends); nullable + default true, like
			// the other rule tables.
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 8]);
			// Named short: oc_kanso_automation_rules (25 chars) overflows the
			// default primary-key name length check, failing install on NC 30-32.
			$table->setPrimaryKey(['id'], 'kanso_autorule_pk');
			$table->addIndex(['board_id', 'trigger'], 'kanso_autorules_board_trig');
		}

		return $schema;
	}
}

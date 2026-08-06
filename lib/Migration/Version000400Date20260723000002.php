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
 * Recurring card rules (`kanso_recur_rules`): board automation that spawns a
 * card on a schedule (RFC 5545 RRULE). A rule points at a template card and a
 * target stack and runs in one of two modes (see {@see \OCA\Kanso\Db\RecurRule}
 * MODE_* constants):
 *   - CLONE: each occurrence creates a fresh copy of the template in the target
 *     stack (title, description, labels, assignees carried over);
 *   - RESET: each occurrence moves the template card itself back to the target
 *     stack and clears its done state (household-chore style).
 *
 * `next_occurrence_at` caches the next fire time so the cron scan is a single
 * indexed range query over (enabled, next_occurrence_at). The spawner is driven
 * by the {@see \OCA\Kanso\Cron\SpawnRecurringCards} cron and the manual
 * create-now endpoint. COUNT/UNTIL exhaustion self-disables the rule
 * (enabled = false, next_occurrence_at = 0).
 *
 * Guarded by hasTable so the step is idempotent.
 */
class Version000400Date20260723000002 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_recur_rules')) {
			return null;
		}

		$table = $schema->createTable('kanso_recur_rules');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('board_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// The card cloned (CLONE mode) or moved back (RESET mode).
		$table->addColumn('template_card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// Stack the spawned/reset card lands in.
		$table->addColumn('target_stack_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// 0 = clone, 1 = reset. See RecurRule MODE_* constants.
		$table->addColumn('mode', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		// RFC 5545 RRULE string, e.g. FREQ=WEEKLY;BYDAY=MO.
		$table->addColumn('rrule', Types::STRING, [
			'notnull' => true,
			'length' => 1024,
		]);
		// 0 = due at the occurrence time; 1 = due at occurrence + offset;
		// 2 = spawned cards get no due date. See RecurRule POLICY_* constants.
		$table->addColumn('duedate_policy', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('duedate_offset_seconds', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// CLONE mode: skip an occurrence while the previously spawned card is
		// still open (not done/archived/deleted).
		$table->addColumn('skip_while_open', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);
		$table->addColumn('enabled', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		// Whose board permissions a spawn runs as.
		$table->addColumn('owner', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('last_spawned_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// Cached next fire time (unix seconds); 0 = exhausted / never.
		$table->addColumn('next_occurrence_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->addColumn('occurrences_spawned', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
		]);
		// Also the DTSTART anchor the RRULE is expanded from.
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// Named short defensively: oc_kanso_recur_rules (20 chars) sits at the
		// edge of the default primary-key name length limit enforced on NC 30-32.
		$table->setPrimaryKey(['id'], 'kanso_recur_pk');
		$table->addIndex(['board_id'], 'kanso_recur_board');
		// The cron scan: enabled rules due to fire (next_occurrence_at <= now).
		$table->addIndex(['enabled', 'next_occurrence_at'], 'kanso_recur_due');

		return $schema;
	}
}

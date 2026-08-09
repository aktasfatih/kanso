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
 * Rich checklist "steps" (#3745, client-collab epic 5): a flat checklist item
 * grows into an assignable step with its own due date. New nullable columns on
 * `kanso_checklist_items` (existing rows stay plain todo lines):
 *
 * - `assigned_user`: the assignee uid (user-only - steps deliberately have no
 *   group assignment).
 * - `assigned_role`: the assignee's board side ('internal'/'external'), COPIED
 *   from the BoardAccess resolver AT ASSIGNMENT TIME and never recomputed - so
 *   the derived wait-state (epic 6, "waiting on client") stays stable even if
 *   the assignee later changes role or leaves the board.
 * - `assigned_at` / `done_at`: integer unix timestamps (the codebase
 *   convention). `done` stays the source of truth; `done_at` is a stamp set
 *   when done flips to 1 and cleared on un-done.
 * - `due_date`: DATETIME, following the Card.duedate precedent (the lone
 *   DATETIME column pattern for user-facing due dates).
 *
 * The (assigned_user, done) index backs the cross-board "my steps" feed
 * (`/api/my-steps`). Guarded (hasColumn/hasIndex) so the step is idempotent.
 */
class Version004900Date20260906000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_checklist_items')) {
			return null;
		}

		$table = $schema->getTable('kanso_checklist_items');
		if (!$table->hasColumn('assigned_user')) {
			$table->addColumn('assigned_user', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}
		if (!$table->hasColumn('assigned_role')) {
			$table->addColumn('assigned_role', Types::STRING, [
				'notnull' => false,
				'length' => 16,
			]);
		}
		if (!$table->hasColumn('assigned_at')) {
			$table->addColumn('assigned_at', Types::BIGINT, [
				'notnull' => false,
				'length' => 8,
			]);
		}
		if (!$table->hasColumn('due_date')) {
			$table->addColumn('due_date', Types::DATETIME, [
				'notnull' => false,
			]);
		}
		if (!$table->hasColumn('done_at')) {
			$table->addColumn('done_at', Types::BIGINT, [
				'notnull' => false,
				'length' => 8,
			]);
		}
		if (!$table->hasIndex('kanso_chk_assigned_idx')) {
			$table->addIndex(['assigned_user', 'done'], 'kanso_chk_assigned_idx');
		}

		return $schema;
	}
}

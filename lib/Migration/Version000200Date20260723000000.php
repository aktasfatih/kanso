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
 * Workflow columns on `kanso_stacks`: a stack `role` (its function in the
 * board pipeline — 0=none, 1=backlog, 2=todo, 3=in_progress, 4=review,
 * 5=done; see {@see \OCA\Kanso\Db\Stack} ROLE_* constants) and an optional
 * `wip_limit` (null = no limit). The done-role is the one the card-move
 * automation reacts to; the rest are advisory client metadata.
 *
 * Both column adds are guarded by hasColumn so the step is idempotent.
 */
class Version000200Date20260723000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_stacks')) {
			return null;
		}

		$table = $schema->getTable('kanso_stacks');
		$changed = false;

		if (!$table->hasColumn('role')) {
			$table->addColumn('role', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('wip_limit')) {
			$table->addColumn('wip_limit', Types::INTEGER, [
				'notnull' => false,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}

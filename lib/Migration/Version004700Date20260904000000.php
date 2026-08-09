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
 * Board member roles (#3742, client-collab epic 2): every `kanso_board_acl`
 * sharing rule carries a `role` - 'internal' (the provider side) or
 * 'external' (the client side). The role feeds the card-visibility model
 * (#3741): 'internal' cards are visible to members sharing the CREATOR's
 * side, symmetrically for both sides, with no owner/admin backdoor.
 *
 * Existing rows backfill to 'internal' via the column default - today's
 * members are all provider-side, so nothing changes for existing boards.
 * The board OWNER has no ACL row; the resolver (BoardAccess) treats them as
 * an implicit (internal, manager) membership.
 *
 * NOTE: `kanso_stacks` also has a `role` column - an unrelated INTEGER
 * workflow-role (to-do/in-progress/done). Same name, different concept.
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version004700Date20260904000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_board_acl')) {
			$table = $schema->getTable('kanso_board_acl');
			if (!$table->hasColumn('role')) {
				$table->addColumn('role', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'internal',
				]);
			}
		}

		return $schema;
	}
}

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
 * Enrich `kanso_changes` for the per-card Activity feed:
 *  - a nullable `verb` (SMALLINT, Change::VERB_* enum) recording WHAT happened
 *    (commented / labelled / assigned / moved …). Additive over the existing
 *    (entity_type, action) sync semantics; a null verb renders as a generic
 *    "updated", so delta-sync is unaffected.
 *  - an index on (entity_type, entity_id) so a card-scoped Activity query is
 *    not a full board-history scan (the log's only prior index is (board_id, id)).
 *
 * Guarded (hasColumn / hasIndex) so the step is idempotent.
 */
class Version002500Date20260813000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_changes')) {
			$table = $schema->getTable('kanso_changes');
			if (!$table->hasColumn('verb')) {
				$table->addColumn('verb', Types::SMALLINT, [
					'notnull' => false,
				]);
			}
			if (!$table->hasIndex('kanso_changes_entity')) {
				$table->addIndex(['entity_type', 'entity_id'], 'kanso_changes_entity');
			}
		}

		return $schema;
	}
}

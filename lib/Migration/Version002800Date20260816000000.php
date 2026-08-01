<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Perf (#3583): add two hot-path secondary indexes that close full-scan hazards
 * on the assignee and label join tables.
 *
 *  - `kanso_card_assignees` (participant, type): the cross-board "My tasks" /
 *    dashboard query {@see \OCA\Kanso\Db\CardMapper::findAssignedInBoards}
 *    filters `participant IN (...) AND type = 0`, but the table's only index is
 *    the unique (card_id, participant, type) which leads with card_id - so the
 *    filter full-scans the assignee table for every user on every dashboard
 *    load. A secondary index leading with (participant, type) makes it a range
 *    seek.
 *  - `kanso_card_labels` (label_id): {@see \OCA\Kanso\Db\CardLabelMapper::deleteByLabel}
 *    runs `DELETE ... WHERE label_id = ?` on label deletion, but the only index
 *    is the unique (card_id, label_id) which leads with card_id - so the delete
 *    full-scans and lock-holds the join table. A secondary index on label_id
 *    makes it a targeted delete.
 *
 * Additive only: the existing unique indexes are kept (reordering them is
 * riskier than adding secondaries), so the board-scoped aggregate queries that
 * join FROM kanso_cards on card_id are unaffected. Guarded (hasTable / hasIndex)
 * so the step is idempotent.
 */
class Version002800Date20260816000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_card_assignees')) {
			$table = $schema->getTable('kanso_card_assignees');
			if (!$table->hasIndex('kanso_card_assign_part')) {
				$table->addIndex(['participant', 'type'], 'kanso_card_assign_part');
			}
		}

		if ($schema->hasTable('kanso_card_labels')) {
			$table = $schema->getTable('kanso_card_labels');
			if (!$table->hasIndex('kanso_card_labels_label')) {
				$table->addIndex(['label_id'], 'kanso_card_labels_label');
			}
		}

		return $schema;
	}
}

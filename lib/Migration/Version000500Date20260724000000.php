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
 * Checklist items (`kanso_checklist_items`): lightweight, FLAT todo lines on a
 * single card (the user-requested "todo items"). Distinct from parent/child
 * cards - these are throwaway sub-tasks, not real cards on the board, so there
 * is deliberately no nesting (no parent_item_id).
 *
 * Items are ordered inside their card by the same fractional `sort_key` string
 * used for cards (see {@see \OCA\Kanso\Service\SortKeyService}); a reorder is a
 * single-row UPDATE. Item mutations append a card-targeted row to
 * `kanso_changes` (via {@see \OCA\Kanso\Service\ChecklistService}) so realtime
 * and the board ETag stay correct, and the board payload carries a per-card
 * done/total progress count.
 *
 * Guarded by hasTable so the step is idempotent.
 */
class Version000500Date20260724000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_checklist_items')) {
			return null;
		}

		$table = $schema->createTable('kanso_checklist_items');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('title', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('done', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);
		$table->addColumn('sort_key', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id']);
		// The only access path: all items of a card, ordered by sort_key. The
		// board-wide progress count joins through kanso_cards on card_id, so a
		// plain card_id index serves both.
		$table->addIndex(['card_id'], 'kanso_checklist_card');

		return $schema;
	}
}

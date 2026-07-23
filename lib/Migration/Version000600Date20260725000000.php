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
 * Parent/child cards: a nullable `parent_card_id` on `kanso_cards`. A card may
 * have one parent card on the same board; children are ordinary cards that also
 * live in their own stack. The hierarchy is deliberately ONE level — the parent
 * has no parent and a child has no children (enforced in
 * {@see \OCA\Kanso\Service\CardService::setParent}); the column alone would
 * allow deeper trees, but the deep-tree UI is out of scope (the sub-issue-tree
 * trap). The child-progress badge and the modal's parent link/children list are
 * driven from this column, joined in-table.
 *
 * The column add is guarded by hasColumn so the step is idempotent.
 */
class Version000600Date20260725000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_cards')) {
			return null;
		}

		$table = $schema->getTable('kanso_cards');
		if ($table->hasColumn('parent_card_id')) {
			return null;
		}

		$table->addColumn('parent_card_id', Types::BIGINT, [
			'notnull' => false,
			'length' => 8,
		]);
		// Listing a card's children and the board-wide child-progress count both
		// filter/group on parent_card_id.
		$table->addIndex(['parent_card_id'], 'kanso_cards_parent');

		return $schema;
	}
}

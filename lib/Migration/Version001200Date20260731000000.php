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
 * Review types (`kanso_review_types`): per-board, user-customizable review
 * categories (QA / Code / Legal / …). Modelled exactly on `kanso_labels` - a
 * board-scoped named+coloured tag - and attached to a review request via a
 * nullable `review_type_id` on `kanso_card_reviews` (an untyped review keeps
 * the plain "Review" meaning). Ordered by id, like labels.
 *
 * A review type is deliberately just a tag: it carries NO per-type workflow,
 * gate, or approver rule. The done-gate stays "all requested reviews approved"
 * regardless of type.
 *
 * Both changes are guarded (hasTable / hasColumn) so the step is idempotent.
 */
class Version001200Date20260731000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable()/getTable()
	 *  are docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs
	 *  (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_review_types')) {
			$table = $schema->createTable('kanso_review_types');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('color', Types::STRING, [
				'notnull' => false,
				'length' => 6,
			]);
			// Named short: oc_kanso_review_types (21 chars) overflows the default
			// primary-key name length check, failing install on NC 30-32.
			$table->setPrimaryKey(['id'], 'kanso_rtype_pk');
			$table->addIndex(['board_id'], 'kanso_review_types_board');
		}

		if ($schema->hasTable('kanso_card_reviews')) {
			$reviews = $schema->getTable('kanso_card_reviews');
			if (!$reviews->hasColumn('review_type_id')) {
				$reviews->addColumn('review_type_id', Types::BIGINT, [
					'notnull' => false,
					'length' => 8,
				]);
			}
		}

		return $schema;
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Allow multiple reviews per (card, reviewer): a person can hold, say, a QA
 * review AND a Code review on the same card. The review identity becomes
 * (card_id, reviewer, review_type_id). `review_type_id` becomes NOT NULL with 0
 * meaning "untyped" (previously NULL) so the composite unique index treats
 * untyped reviews as a single row per reviewer - a nullable column can't (NULLs
 * are distinct in a unique index).
 *
 * preSchemaChange backfills NULL → 0 before the NOT NULL alter.
 */
class Version001600Date20260804000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	#[\Override]
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->db->tableExists('kanso_card_reviews')) {
			return;
		}
		// Untyped reviews used NULL; make them 0 so the new NOT NULL column and
		// the composite unique index are satisfiable.
		$qb = $this->db->getQueryBuilder();
		$qb->update('kanso_card_reviews')
			->set('review_type_id', $qb->createNamedParameter(0))
			->where($qb->expr()->isNull('review_type_id'));
		$qb->executeStatement();
	}

	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('kanso_card_reviews')) {
			return null;
		}
		$table = $schema->getTable('kanso_card_reviews');

		$column = $table->getColumn('review_type_id');
		$column->setNotnull(true);
		$column->setDefault(0);

		if ($table->hasIndex('kanso_reviews_card_reviewer')) {
			$table->dropIndex('kanso_reviews_card_reviewer');
		}
		if (!$table->hasIndex('kanso_reviews_card_rev_type')) {
			$table->addUniqueIndex(['card_id', 'reviewer', 'review_type_id'], 'kanso_reviews_card_rev_type');
		}

		return $schema;
	}
}

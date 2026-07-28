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
 * Card start date (`kanso_cards.start_date`): a nullable schedule start that
 * pairs with the existing due date to draw a start→due bar on the timeline
 * (Gantt) view. Due-only cards render as a milestone; cards with neither are
 * "unscheduled". Purely additive and nullable - no backfill needed.
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version001700Date20260805000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_cards')) {
			$table = $schema->getTable('kanso_cards');
			if (!$table->hasColumn('start_date')) {
				$table->addColumn('start_date', Types::DATETIME, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}

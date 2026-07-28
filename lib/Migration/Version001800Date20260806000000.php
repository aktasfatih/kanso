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
 * Card started state (`kanso_cards.started_at`): the symmetric twin of
 * `done_at`. Card status is DERIVED - done_at > 0 → Done, else started_at > 0 →
 * In progress, else Not started - so there's no separate status enum to drift.
 * Moving a card into an IN_PROGRESS-role stack stamps it (like DONE stamps
 * done). Additive with a 0 default; no backfill needed.
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version001800Date20260806000000 extends SimpleMigrationStep {
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
			if (!$table->hasColumn('started_at')) {
				$table->addColumn('started_at', Types::BIGINT, [
					'notnull' => true,
					'default' => 0,
					'length' => 8,
				]);
			}
		}

		return $schema;
	}
}

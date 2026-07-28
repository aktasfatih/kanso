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
 * Card priority on `kanso_cards`: a small integer 0..4
 * (0=none, 1=low, 2=medium, 3=high, 4=urgent; see {@see \OCA\Kanso\Db\Card}
 * PRIORITY_* constants). Carried in the summary payload so tiles render the
 * priority indicator and the board filter can select by level; it is a plain
 * attribute - it does NOT reorder cards (sort keys stay the ordering source).
 *
 * The column add is guarded by hasColumn so the step is idempotent.
 */
class Version000700Date20260726000000 extends SimpleMigrationStep {
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
		if ($table->hasColumn('priority')) {
			return null;
		}

		$table->addColumn('priority', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);

		return $schema;
	}
}

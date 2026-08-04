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
 * Board background (#3528): a nullable `background` column on `kanso_boards`
 * holding a small CURATED preset KEY (validated server-side by
 * {@see \OCA\Kanso\Service\BackgroundValidator}), never free-form CSS. NULL =
 * no background. Deck parity for "change board background", presets only.
 *
 * Guarded by hasColumn so the step is idempotent and safe to re-run.
 */
class Version004100Date20260829000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs
	 *  (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_boards')) {
			return null;
		}

		$table = $schema->getTable('kanso_boards');
		if ($table->hasColumn('background')) {
			return null;
		}

		// A preset key like "ocean"; length is generous for future keys but the
		// value is always allow-listed before it is stored.
		$table->addColumn('background', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);

		return $schema;
	}
}

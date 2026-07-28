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
 * Stack colour (`kanso_stacks.color`): a nullable bare-6-hex colour (mirrors
 * `kanso_boards.color` / label colours) used to tint the column header. The
 * '#' is only ever added at the CSS boundary; the wire/storage value is bare
 * hex, validated by {@see \OCA\Kanso\Service\ColorValidator}. Additive and
 * nullable - no backfill.
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version002300Date20260811000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_stacks')) {
			$table = $schema->getTable('kanso_stacks');
			if (!$table->hasColumn('color')) {
				$table->addColumn('color', Types::STRING, [
					'notnull' => false,
					'length' => 6,
				]);
			}
		}

		return $schema;
	}
}

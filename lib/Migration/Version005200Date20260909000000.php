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
 * Public-link "show comments" opt-in (#3949): a nullable boolean
 * `public_share_comments` on `kanso_boards`, default false. The public board's
 * baseline promise is that no person data is ever shown; this per-share flag
 * lets a MANAGE user DELIBERATELY widen an intentionally person-free surface so
 * an anonymous reader sees each public card's read-only comment thread (author
 * DISPLAY NAME only - never a uid, reaction or member). OFF by default, so a
 * board that predates the column keeps the "never shown" guarantee unchanged.
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version005200Date20260909000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_boards')) {
			return null;
		}

		$table = $schema->getTable('kanso_boards');
		if (!$table->hasColumn('public_share_comments')) {
			$table->addColumn('public_share_comments', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
		}

		return $schema;
	}
}

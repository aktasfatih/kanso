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
 * A free-text description on a column (`kanso_stacks.description`).
 *
 * A stack carried a title and a workflow `role`, but nothing that says what
 * belongs in it ("only cards with a reproducer", "waiting on the customer").
 * That explanation lived in people's heads, and an agent reading the board over
 * the MCP had no way to learn it at all.
 *
 * Nullable TEXT, exactly like `kanso_projects.description`: null = no
 * description (the overwhelming majority of columns), and the service caps a
 * stored value well below what TEXT holds. Plain text - it is never rendered as
 * markdown or HTML.
 *
 * Guarded (hasTable / hasColumn) so the step is idempotent.
 */
class Version006400Date20260922000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_stacks')) {
			return null;
		}

		$table = $schema->getTable('kanso_stacks');
		if ($table->hasColumn('description')) {
			return null;
		}

		$table->addColumn('description', Types::TEXT, [
			'notnull' => false,
			'default' => null,
		]);

		return $schema;
	}
}

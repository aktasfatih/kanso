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
 * A free-text description on a board (`kanso_boards.description`).
 *
 * A board carried a title and nothing that says what it is FOR - its purpose,
 * its scope, how the team is meant to use it. That lived in people's heads, and
 * an assistant reading the board over the MCP had no way to learn it at all
 * (#173).
 *
 * Nullable TEXT, exactly like `kanso_stacks.description` and
 * `kanso_projects.description`: null = no description (every board that predates
 * this column, so the upgrade is a no-op), and the service caps a stored value
 * well below what TEXT holds. Unlike the column description this one is
 * MARKDOWN - it is rendered by the same sanitising renderer the card body uses,
 * so the storage stays raw source and nothing is pre-rendered here.
 *
 * Guarded (hasTable / hasColumn) so the step is idempotent.
 */
class Version006500Date20261002000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_boards')) {
			return null;
		}

		$table = $schema->getTable('kanso_boards');
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

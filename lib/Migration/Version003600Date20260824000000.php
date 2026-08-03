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
 * Card type (#3402): a fixed built-in "issue type" for a card - exactly one per
 * card, icon-first, lighter than a label. A small string enum stored on
 * `kanso_cards`:
 *  - `type` one of 'bug' | 'feature' | 'task' | 'chore', or NULL for the
 *           implicit "none" (the summary serializes NULL as '' - Card::TYPE_NONE).
 *           Nullable (same convention as cover_color) so it is portable across
 *           DB backends - a NOT NULL column with an empty-string default is
 *           rejected by Postgres. Serialized into the card summary so the tile
 *           can render the type icon without loading the detail. The built-in set
 *           is fixed (no custom-type editor) and validated in CardService.
 *
 * Guarded (hasTable / hasColumn) so the step is idempotent and safe on a fresh
 * install.
 *
 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is docblocked
 *  as Doctrine\DBAL\Schema\Table, not part of the OCP stubs (Deck suppresses the
 *  same class in its psalm config).
 */
class Version003600Date20260824000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_cards')) {
			return null;
		}

		$table = $schema->getTable('kanso_cards');
		if ($table->hasColumn('type')) {
			return null;
		}

		$table->addColumn('type', Types::STRING, [
			'notnull' => false,
			'length' => 16,
			'default' => null,
		]);

		return $schema;
	}
}

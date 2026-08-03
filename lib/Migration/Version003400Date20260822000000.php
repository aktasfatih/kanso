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
 * Card cover colour (#3549): a nullable per-card cover colour rendered as a
 * band/strip on the card tile (Trello-style scannability).
 *
 * A single nullable column on `kanso_cards`:
 *  - `cover_color` the bare 6-hex cover colour (no leading '#', same convention
 *                  as label/stack/board colours - see ColorValidator), or NULL
 *                  when the card has no cover. Serialized into the card summary
 *                  so the tile can render the band without loading the detail.
 *
 * Guarded (hasTable / hasColumn) so the step is idempotent and safe on a fresh
 * install.
 *
 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is docblocked
 *  as Doctrine\DBAL\Schema\Table, not part of the OCP stubs (Deck suppresses the
 *  same class in its psalm config).
 */
class Version003400Date20260822000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_cards')) {
			return null;
		}

		$table = $schema->getTable('kanso_cards');
		if ($table->hasColumn('cover_color')) {
			return null;
		}

		$table->addColumn('cover_color', Types::STRING, [
			'notnull' => false,
			'length' => 6,
			'default' => null,
		]);

		return $schema;
	}
}

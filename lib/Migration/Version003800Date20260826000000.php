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
 * Per-board card templates (#3409): a boolean `is_template` flag on
 * `kanso_cards`.
 *
 * A template is an ordinary card that has been flagged as a reusable content
 * blueprint for ITS board only. Flagged cards are EXCLUDED from the live board
 * render (they are not live work) and listed in a small per-board picker; a new
 * card is created from a template by cloning its title/description/labels/
 * checklist (the same content clone the copy-card feature uses).
 *
 * Nullable boolean with a `false` default - the SAME shape as the existing
 * `archived` flag on this table. A NOT NULL boolean with a false default is
 * rejected by Postgres under Nextcloud's schema layer, so the column is nullable;
 * the `default => false` backfills every existing card to a normal (non-template)
 * card, and the create path writes `false` explicitly, so the board-render filter
 * (`is_template = false`) matches every live card. The entity treats NULL as false.
 *
 * Guarded (hasTable / hasColumn) so the step is idempotent and safe on a fresh
 * install.
 *
 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is docblocked
 *  as Doctrine\DBAL\Schema\Table, not part of the OCP stubs (Deck suppresses the
 *  same class in its psalm config).
 */
class Version003800Date20260826000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_cards')) {
			return null;
		}

		$table = $schema->getTable('kanso_cards');
		if ($table->hasColumn('is_template')) {
			return null;
		}

		$table->addColumn('is_template', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}

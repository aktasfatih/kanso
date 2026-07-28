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
 * Card estimations (#3443). Adds a per-board estimate SCALE
 * (`kanso_boards.estimate_scale`, default 'none' = disabled) and a per-card
 * `kanso_cards.estimate` token drawn from that scale (nullable). The estimate
 * is stored as a string so one column carries both numeric (fibonacci/linear/
 * hours) and textual (t-shirt) scales; see {@see \OCA\Kanso\Db\EstimateScale}.
 *
 * It is a plain sizing attribute - carried in the summary payload for the tile
 * chip, but it does NOT reorder cards, and no burndown/velocity is derived from
 * it (charter non-goal). Both column adds are guarded by hasColumn (idempotent).
 */
class Version002000Date20260808000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_cards')) {
			$cards = $schema->getTable('kanso_cards');
			if (!$cards->hasColumn('estimate')) {
				$cards->addColumn('estimate', Types::STRING, [
					'notnull' => false,
					'length' => 8,
				]);
			}
		}

		if ($schema->hasTable('kanso_boards')) {
			$boards = $schema->getTable('kanso_boards');
			if (!$boards->hasColumn('estimate_scale')) {
				$boards->addColumn('estimate_scale', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'none',
				]);
			}
		}

		return $schema;
	}
}

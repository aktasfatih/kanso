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
 * Card visibility model (#3741, client-collab epic 1): two columns on
 * `kanso_cards`:
 *
 * - `visibility`: 'public' (all board members - the default: whoever
 *   decides nothing works in the open), 'internal' (members sharing the
 *   CREATOR's board side, symmetric for both sides), or 'private'
 *   (creator only, matched via the existing `owner` column).
 * - `creator_role`: the creator's board side ('internal'/'external'),
 *   FROZEN at create and never recomputed - so an 'internal' card keeps
 *   its side even when the creator later changes role or leaves the
 *   board. Pre-existing rows backfill to 'internal' (provider-side).
 *
 * Existing rows backfill to public/internal via the column defaults - every
 * card stays visible to every board member, so behavior for existing boards
 * is exactly unchanged. The (board_id, visibility, creator_role) index
 * backs the visibility WHERE that CardVisibilityScope appends to card
 * queries (wired into the read paths by epic 3).
 *
 * Guarded (hasColumn/hasIndex) so the step is idempotent.
 */
class Version004800Date20260905000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_cards')) {
			$table = $schema->getTable('kanso_cards');
			if (!$table->hasColumn('visibility')) {
				$table->addColumn('visibility', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'public',
				]);
			}
			if (!$table->hasColumn('creator_role')) {
				$table->addColumn('creator_role', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'internal',
				]);
			}
			if (!$table->hasIndex('kanso_cards_vis_idx')) {
				$table->addIndex(['board_id', 'visibility', 'creator_role'], 'kanso_cards_vis_idx');
			}
		}

		return $schema;
	}
}

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
 * Public / read-only board share links (#3531). A MANAGE user opts a board in by
 * minting a long, unguessable `public_share_token` (ISecureRandom, 64 chars); an
 * unauthenticated reader who has the token gets a STRIPPED, read-only view of the
 * board. Disabling clears the token (revocable), rotating replaces it - a stale
 * token stops resolving immediately.
 *
 * Two nullable columns on `kanso_boards`:
 *  - `public_share_token`   the random token, or NULL when sharing is off. A
 *                           UNIQUE index makes the token → exactly-one-board
 *                           lookup a single indexed probe (no enumeration pivot
 *                           to other boards) and enforces global uniqueness.
 *  - `public_share_expires_at` optional unix-ts expiry (0 / NULL = never). v1
 *                           defaults to no expiry (revocable-until-disabled).
 *
 * The unique index is named `kanso_board_pubtok` (globally unique) so the step is
 * safe on a fresh install. Guarded column-by-column so re-running is idempotent.
 *
 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is docblocked
 *  as Doctrine\DBAL\Schema\Table, not part of the OCP stubs (Deck suppresses the
 *  same class in its psalm config).
 */
class Version003300Date20260821000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_boards')) {
			return null;
		}

		$table = $schema->getTable('kanso_boards');
		$changed = false;

		if (!$table->hasColumn('public_share_token')) {
			$table->addColumn('public_share_token', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('public_share_expires_at')) {
			$table->addColumn('public_share_expires_at', Types::BIGINT, [
				'notnull' => false,
				'length' => 8,
				'default' => null,
			]);
			$changed = true;
		}

		// A UNIQUE index on the token: the public-read path resolves a token to
		// exactly one board with one indexed lookup, and no two boards can ever
		// share a token. NULLs (sharing off) are allowed to repeat.
		if (!$table->hasIndex('kanso_board_pubtok')) {
			$table->addUniqueIndex(['public_share_token'], 'kanso_board_pubtok');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}

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
 * iCal / ICS read-only feed of card due dates (#3541). A MANAGE user opts a board
 * in by minting a long, unguessable `ical_feed_token` (ISecureRandom, 64 chars);
 * any calendar client that has the token can subscribe to a read-only VCALENDAR
 * with one VEVENT per card that has a due date. Disabling clears the token
 * (revocable), rotating replaces it - a stale token 404s on the very next fetch.
 *
 * This is a DISTINCT token from `public_share_token` (#3531) on purpose: the two
 * features have independent lifecycles (a board can offer the calendar feed
 * without a public board share, and vice versa), so disabling one must never kill
 * the other.
 *
 * One nullable column on `kanso_boards`:
 *  - `ical_feed_token`  the random token, or NULL when the feed is off. A UNIQUE
 *                       index makes the token -> exactly-one-board lookup a single
 *                       indexed probe (no enumeration pivot to other boards) and
 *                       enforces global uniqueness.
 *
 * The unique index is named `kanso_board_icaltok` (globally unique) so the step is
 * safe on a fresh install. Guarded column-by-column so re-running is idempotent.
 *
 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is docblocked
 *  as Doctrine\DBAL\Schema\Table, not part of the OCP stubs (Deck suppresses the
 *  same class in its psalm config).
 */
class Version003900Date20260827000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_boards')) {
			return null;
		}

		$table = $schema->getTable('kanso_boards');
		$changed = false;

		if (!$table->hasColumn('ical_feed_token')) {
			$table->addColumn('ical_feed_token', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
		}

		// A UNIQUE index on the token: the public feed path resolves a token to
		// exactly one board with one indexed lookup, and no two boards can ever
		// share a token. NULLs (feed off) are allowed to repeat.
		if (!$table->hasIndex('kanso_board_icaltok')) {
			$table->addUniqueIndex(['ical_feed_token'], 'kanso_board_icaltok');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}

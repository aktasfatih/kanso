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
 * Timezone-stable recurrence (#3587): add a nullable `timezone` column to
 * `kanso_recur_rules`.
 *
 * A rule's RRULE is now anchored with a DateTimeImmutable in this IANA timezone
 * (e.g. `Europe/Berlin`) rather than a bare UTC `@timestamp`, so occurrences are
 * floating wall-clock times (RFC 5545 / CalDAV): "daily at 09:00" fires 09:00
 * local on both sides of a DST boundary, and the UTC instant shifts to preserve
 * the local hour. New rules default the column to the owner's Nextcloud personal
 * timezone (falling back to the server default). Existing rules keep NULL and
 * fall back to the server timezone at expansion time - documented back-compat,
 * and their already-cached `next_occurrence_at` is left untouched.
 *
 * Guarded by hasColumn so the step is idempotent.
 */
class Version002700Date20260815000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_recur_rules')) {
			return null;
		}

		$table = $schema->getTable('kanso_recur_rules');
		if ($table->hasColumn('timezone')) {
			return null;
		}

		// IANA timezone id (e.g. Europe/Berlin). Nullable: NULL means "use the
		// server default timezone", the back-compat path for pre-#3587 rules.
		$table->addColumn('timezone', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);

		return $schema;
	}
}

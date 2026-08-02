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
 * Due-date reminder notifications (#3545): the markers that make the reminder
 * cron ({@see \OCA\Kanso\Cron\SendDueReminders}) idempotent, plus the per-card
 * "1 day before" opt-in.
 *
 * Schema on `kanso_cards`:
 *  - `due_reminder_sent`       bigint - unix ts the at-due reminder fired, 0 =
 *                              not yet. Reset to 0 whenever the due date CHANGES
 *                              (see {@see \OCA\Kanso\Service\CardService::update})
 *                              so a moved due date re-arms the reminder.
 *  - `day_before_reminder_sent` bigint - the same marker for the optional
 *                              "1 day before" reminder.
 *  - `due_reminder_day_before` boolean - a fixed, card-level toggle: when true
 *                              the card also gets a reminder 24h before due.
 *                              Card-level (not a board setting, not a per-user
 *                              preference matrix) is the simplest maintainable
 *                              placement per the card's ship bar.
 *
 * All columns are guarded (hasColumn) so the step is idempotent and safe on
 * fresh installs.
 */
class Version003000Date20260818000000 extends SimpleMigrationStep {
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
			if (!$table->hasColumn('due_reminder_sent')) {
				$table->addColumn('due_reminder_sent', Types::BIGINT, [
					'notnull' => true,
					'length' => 8,
					'unsigned' => true,
					'default' => 0,
				]);
			}
			if (!$table->hasColumn('day_before_reminder_sent')) {
				$table->addColumn('day_before_reminder_sent', Types::BIGINT, [
					'notnull' => true,
					'length' => 8,
					'unsigned' => true,
					'default' => 0,
				]);
			}
			if (!$table->hasColumn('due_reminder_day_before')) {
				$table->addColumn('due_reminder_day_before', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
			}
		}

		return $schema;
	}
}

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
 * GitHub issue intake (#3752): two per-board webhook-intake columns on
 * `kanso_boards`, next to `webhook_secret`:
 *
 * - `webhook_intake_stack_id`: the stack a card is auto-created in when the
 *   board's webhook receives an `issues`/`opened` delivery. Nullable - NULL
 *   means intake is OFF (the default; the webhook keeps its react-only
 *   behavior).
 * - `webhook_intake_label`: an optional free-text GitHub label name; when set,
 *   only opened issues carrying that label (per the event payload) are taken
 *   in. NULL = all issues.
 *
 * Both are MANAGE-only config and are never included in the board payload
 * (Board::jsonSerialize omits them; they ride the webhook config endpoint).
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version004600Date20260903000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_boards')) {
			$table = $schema->getTable('kanso_boards');
			if (!$table->hasColumn('webhook_intake_stack_id')) {
				$table->addColumn('webhook_intake_stack_id', Types::BIGINT, [
					'notnull' => false,
					'length' => 8,
				]);
			}
			if (!$table->hasColumn('webhook_intake_label')) {
				$table->addColumn('webhook_intake_label', Types::STRING, [
					'notnull' => false,
					'length' => 100,
				]);
			}
		}

		return $schema;
	}
}

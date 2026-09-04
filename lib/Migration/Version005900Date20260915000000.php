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
 * Forgejo integration: a board can run a Forgejo webhook alongside (not instead
 * of) its GitHub one, so the config gets its own columns rather than sharing
 * `webhook_secret`:
 *
 * - `forgejo_webhook_secret`: the HMAC key for the board's Forgejo ingest
 *   endpoint. NULL means the Forgejo webhook is disabled for that board.
 * - `forgejo_intake_stack_id` / `forgejo_intake_label`: the Forgejo half of
 *   issue intake, mirroring the GitHub columns (NULL stack = intake off).
 *
 * There is deliberately NO instance-URL column. Kanso never calls a Forgejo
 * instance - deliveries are inbound only - so it needs no host, no token and no
 * outbound HTTP for this feature.
 *
 * `kanso_card_links.provider` records which forge a link belongs to so the
 * read-time poll can stay github.com-only: existing rows backfill to 'github'
 * via the column default, and Forgejo links are never polled.
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version005900Date20260915000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs
	 *  (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_boards')) {
			$table = $schema->getTable('kanso_boards');
			if (!$table->hasColumn('forgejo_webhook_secret')) {
				$table->addColumn('forgejo_webhook_secret', Types::STRING, [
					'notnull' => false,
					'length' => 128,
				]);
			}
			if (!$table->hasColumn('forgejo_intake_stack_id')) {
				$table->addColumn('forgejo_intake_stack_id', Types::BIGINT, [
					'notnull' => false,
					'length' => 8,
				]);
			}
			if (!$table->hasColumn('forgejo_intake_label')) {
				$table->addColumn('forgejo_intake_label', Types::STRING, [
					'notnull' => false,
					'length' => 100,
				]);
			}
		}

		if ($schema->hasTable('kanso_card_links')) {
			$table = $schema->getTable('kanso_card_links');
			if (!$table->hasColumn('provider')) {
				$table->addColumn('provider', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'github',
				]);
			}
		}

		return $schema;
	}
}

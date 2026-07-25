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
 * Per-board GitHub webhook secret (`kanso_boards.webhook_secret`): an HMAC key
 * used to verify inbound `X-Hub-Signature-256` on the board's webhook endpoint.
 * Nullable — no secret means the webhook is disabled for that board. The value
 * is MANAGE-only and is never included in the board payload (Board::jsonSerialize
 * deliberately omits it).
 *
 * Guarded (hasColumn) so the step is idempotent.
 */
class Version001500Date20260803000000 extends SimpleMigrationStep {
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
			if (!$table->hasColumn('webhook_secret')) {
				$table->addColumn('webhook_secret', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
			}
		}

		return $schema;
	}
}

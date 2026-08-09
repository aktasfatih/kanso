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
 * Per-board "project chat" link (#3748, client-collab epic 8): a nullable
 * `chat_url` on `kanso_boards`. Deliberately dumb - a pure display address
 * (typically a Nextcloud Talk room) surfaced as a one-click button; no Talk
 * API coupling, no secret, no notification integration. MANAGE-only to set
 * (server-validated http/https), visible to every board member when set.
 * NULL = no chat link. Guarded (hasColumn) so the step is idempotent.
 */
class Version005000Date20260907000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_boards')) {
			return null;
		}

		$table = $schema->getTable('kanso_boards');
		if (!$table->hasColumn('chat_url')) {
			$table->addColumn('chat_url', Types::STRING, [
				'notnull' => false,
				'length' => 4000,
			]);
		}

		return $schema;
	}
}

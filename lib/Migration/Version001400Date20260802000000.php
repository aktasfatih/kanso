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
 * Card links (`kanso_card_links`): manual GitHub PR/issue URLs attached to a
 * card, rendered as rich chips with a best-effort state badge. `state` is
 * refreshed by an unauthenticated GitHub API poll (throttled via `last_polled`)
 * — private/rate-limited stays `unknown`. No credentials are stored.
 *
 * Guarded (hasTable) so the step is idempotent.
 */
class Version001400Date20260802000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs
	 *  (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_card_links')) {
			$table = $schema->createTable('kanso_card_links');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('card_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('url', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('kind', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'other',
			]);
			$table->addColumn('state', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'unknown',
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => false,
				'length' => 512,
			]);
			$table->addColumn('last_polled', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'default' => 0,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['card_id'], 'kanso_card_links_card');
			// One row per (card, url) — a re-paste of the same URL is idempotent.
			$table->addUniqueIndex(['card_id', 'url'], 'kanso_card_links_uniq');
		}

		return $schema;
	}
}

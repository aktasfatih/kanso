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
 * Card / thread subscriptions (`kanso_subscriptions`): watchers. A row is a user
 * watching a card (comment_thread_id = 0) or one discussion thread under it
 * (comment_thread_id = the top-level comment id). `state` distinguishes an
 * active subscription (0) from an explicit opt-out tombstone (1) - the tombstone
 * makes a manual unsubscribe stick, so later auto-subscribe (commenting /
 * assignment) never silently re-adds a watcher who opted out.
 *
 * comment_thread_id defaults to 0 (card-level) rather than NULL so the unique
 * index (subscriber, card_id, comment_thread_id) enforces one row per scope
 * across all DBs (NULLs are distinct in unique indexes on MySQL/Postgres).
 *
 * Guarded by hasTable so the step is idempotent.
 */
class Version000900Date20260728000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_subscriptions')) {
			return null;
		}

		$table = $schema->createTable('kanso_subscriptions');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('subscriber', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// 0 = whole card; otherwise the top-level comment id of a watched thread.
		$table->addColumn('comment_thread_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// 0 = subscribed, 1 = opted-out tombstone. See Subscription STATE_* consts.
		$table->addColumn('state', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// Named short: oc_kanso_subscriptions (22 chars) overflows the default
		// primary-key name length check, failing install on NC 30-32.
		$table->setPrimaryKey(['id'], 'kanso_sub_pk');
		$table->addUniqueIndex(['subscriber', 'card_id', 'comment_thread_id'], 'kanso_subs_unique');
		// Fan-out: all subscribers of a card (+ its threads).
		$table->addIndex(['card_id'], 'kanso_subs_card');

		return $schema;
	}
}

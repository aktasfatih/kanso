<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Migration;

use Closure;
use OCA\Kanso\Cron\SendPersonalReminders;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Personal, one-shot "remind me" on cards / comments (#3816): the new
 * `kanso_reminders` table. A row is a single per-user reminder about a card
 * (optionally a specific comment); the 15-minute
 * {@see \OCA\Kanso\Cron\SendPersonalReminders} sweep notifies the setter once
 * at `remind_at` and stamps `fired_at`.
 *
 * `comment_id` is nullable (a card-level reminder carries none). `fired_at` is
 * nullable - null means still pending. Indexed on (fired_at) for the cron's
 * due scan and on (user_id, card_id) for the card-detail "your reminders" list.
 *
 * Guarded (hasTable) so the step is idempotent.
 *
 * postSchemaChange also registers the {@see SendPersonalReminders} cron job:
 * Nextcloud only syncs info.xml <background-jobs> on a FRESH install, not on an
 * upgrade of an existing install, so a job newly added in this version would
 * otherwise never be registered on upgrading instances (and reminders would
 * silently never fire). Registering it here covers install + upgrade; the
 * jobList add is idempotent-guarded.
 */
class Version005100Date20260908000000 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_reminders')) {
			return null;
		}

		$table = $schema->createTable('kanso_reminders');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('comment_id', Types::BIGINT, [
			'notnull' => false,
			'length' => 20,
		]);
		$table->addColumn('remind_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('fired_at', Types::BIGINT, [
			'notnull' => false,
			'length' => 20,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'default' => 0,
		]);

		$table->setPrimaryKey(['id']);
		// The cron's due scan filters on fired_at IS NULL + remind_at.
		$table->addIndex(['fired_at', 'remind_at'], 'kanso_rem_due');
		// The card-detail "your reminders" list is per (user, card).
		$table->addIndex(['user_id', 'card_id'], 'kanso_rem_user_card');
		// Card-purge cascade deletes by card_id.
		$table->addIndex(['card_id'], 'kanso_rem_card');

		return $schema;
	}

	/**
	 * Ensure the personal-reminder sweep is registered on this instance. Runs on
	 * install and on upgrade (info.xml only auto-registers <background-jobs> on a
	 * fresh install), guarded so re-running is a no-op.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->jobList->has(SendPersonalReminders::class, null)) {
			$this->jobList->add(SendPersonalReminders::class);
			$output->info('Registered background job: SendPersonalReminders');
		}
	}
}

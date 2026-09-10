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
 * Abuse controls for email intake (#117), on top of
 * {@see Version006000Date20260916000000}.
 *
 * Three additions, all driven by the same fact: intake is the only path in the
 * app where an unauthenticated stranger causes a write.
 *
 * - `require_auth` on `kanso_mail_intake`: only card messages the receiving MTA
 *   authenticated (DMARC). Off by default, because it depends on the deployment
 *   actually running such an MTA - see docs/email-intake-security.md.
 * - `daily_state`: today's counters as JSON - `{"day":20260917,"total":12,
 *   "senders":{"a@b":3}}`. One column rather than a counters table because the
 *   whole value is rewritten once per poll and never queried across rows; a
 *   table would buy nothing but joins. Reset by day, so it cannot grow without
 *   bound.
 * - `kanso_mail_seen`: dedupe keys of messages already carded, so a mailbox
 *   that re-delivers (or a UIDVALIDITY reset that rewinds the watermark) cannot
 *   card the same message twice. The UNIQUE index is the actual guard - the
 *   insert failing IS the duplicate check, which is race-free in a way a
 *   read-then-write is not.
 *
 * Both tables/columns are guarded so the step is idempotent.
 */
class Version006100Date20260917000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::getTable() and
	 *  createTable() are docblocked as Doctrine\DBAL\Schema\Table, not part of
	 *  the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_mail_intake')) {
			$table = $schema->getTable('kanso_mail_intake');

			if (!$table->hasColumn('require_auth')) {
				// Nullable for the same reason as `enabled` in the migration this
				// builds on: Nextcloud rejects a NOT NULL boolean that defaults to
				// false, because Oracle stores false as an empty string and reads
				// that back as NULL.
				$table->addColumn('require_auth', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
			}
			if (!$table->hasColumn('daily_state')) {
				$table->addColumn('daily_state', Types::TEXT, [
					'notnull' => false,
				]);
			}
			// Per-mailbox overrides for the abuse caps. 0 = use the service
			// default; an explicit value lets a busy team raise it (or a public
			// address lower it) without a code change.
			if (!$table->hasColumn('daily_limit')) {
				$table->addColumn('daily_limit', Types::INTEGER, [
					'notnull' => true,
					'default' => 0,
				]);
			}
			if (!$table->hasColumn('per_sender_daily_limit')) {
				$table->addColumn('per_sender_daily_limit', Types::INTEGER, [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		if (!$schema->hasTable('kanso_mail_seen')) {
			$table = $schema->createTable('kanso_mail_seen');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('intake_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			// A hash, never the Message-ID itself: it is sender-controlled text of
			// unbounded length, and a fixed-width hash indexes far better. 64 chars
			// = sha256 hex, prefixed by the key kind (see MimeMessage::dedupeKey).
			$table->addColumn('dedupe_key', Types::STRING, [
				'notnull' => true,
				'length' => 72,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id'], 'kanso_mailseen_pk');
			// THE guard: a duplicate insert fails rather than being detected.
			$table->addUniqueIndex(['intake_id', 'dedupe_key'], 'kanso_mailseen_uniq');
			// Pruning scans by age.
			$table->addIndex(['created_at'], 'kanso_mailseen_age_idx');
		}

		return $schema;
	}
}

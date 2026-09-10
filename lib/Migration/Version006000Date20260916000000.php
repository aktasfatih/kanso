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
 * Email intake (#117): `kanso_mail_intake` - one polled IMAP mailbox per board,
 * turning inbound mail into cards.
 *
 * Modelled on the forge-webhook config, with one structural difference: the
 * webhook config rides COLUMNS on `kanso_boards` because it is three small
 * scalars, whereas a mailbox is a dozen fields including a credential. Those
 * belong in their own row so a board read - the hottest query in the app - does
 * not carry an encrypted password it never uses.
 *
 * A board has at most ONE mailbox: `board_id` is UNIQUE, which makes the config
 * write an upsert and keeps "which mailbox feeds this board" unambiguous.
 *
 * Columns worth explaining:
 * - `password` is TEXT, not STRING: it holds the ICrypto CIPHERTEXT (base64 with
 *   an IV and HMAC appended), which is several times longer than the secret and
 *   grows with NC's cipher choice. It is never stored or logged in the clear.
 * - `last_uid` is the intake watermark - the highest IMAP UID already turned
 *   into a card. Polling asks the server for `UID <n+1>:*` rather than tracking
 *   the \Seen flag, so a human reading the mailbox in a mail client cannot make
 *   the poller skip messages, and a re-delivered message is never carded twice.
 *   It is reset to 0 when the mailbox's `uidvalidity` changes (the server
 *   renumbered; see `uid_validity`).
 * - `uid_validity` pairs with `last_uid`. IMAP UIDs are only meaningful within
 *   one UIDVALIDITY generation; without this a renumbered mailbox would either
 *   replay every message or silently ingest none.
 * - `last_error` holds the most recent failure text for the config UI. A poll
 *   failure must be visible - a mailbox that quietly stopped ingesting looks
 *   exactly like a mailbox nobody wrote to.
 *
 * hasTable-guarded so the step is idempotent; index names are globally unique.
 */
class Version006000Date20260916000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable()
	 *  is docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs.
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_mail_intake')) {
			$table = $schema->createTable('kanso_mail_intake');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			// The stack new cards land in. Not a FK (no table in this app uses
			// them); a stack deleted out from under the config is handled at poll
			// time, which parks the mailbox with an error rather than dropping mail.
			$table->addColumn('stack_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('host', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('port', Types::INTEGER, [
				'notnull' => true,
				'default' => 993,
			]);
			// 'ssl' (implicit TLS, port 993) or 'tls' (STARTTLS on 143). Plaintext
			// IMAP is deliberately not an option - see MailIntakeService::ENCRYPTIONS.
			$table->addColumn('encryption', Types::STRING, [
				'notnull' => true,
				'length' => 8,
				'default' => 'ssl',
			]);
			$table->addColumn('username', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('password', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('mailbox', Types::STRING, [
				'notnull' => true,
				'length' => 255,
				'default' => 'INBOX',
			]);
			// Newline-separated sender addresses permitted to create cards. Empty
			// = accept any sender, which is only safe for a mailbox nobody else
			// knows the address of; the config UI says so.
			$table->addColumn('sender_allowlist', Types::TEXT, [
				'notnull' => false,
			]);
			// Nullable, like every other boolean in this schema. A NOT NULL
			// boolean defaulting to false is rejected by Nextcloud's migration
			// validation ("is type Bool and also NotNull, so it can not store
			// false") because Oracle stores false as an empty string, which it
			// then treats as NULL. The entity casts on read, so a null reads as
			// false either way.
			$table->addColumn('enabled', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('last_uid', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 8,
			]);
			$table->addColumn('uid_validity', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 8,
			]);
			$table->addColumn('last_run', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('last_error', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id'], 'kanso_mailin_pk');
			// One mailbox per board - the config write is an upsert on this.
			$table->addUniqueIndex(['board_id'], 'kanso_mailin_board_uniq');
			// The cron's work list: every enabled mailbox across all boards.
			$table->addIndex(['enabled'], 'kanso_mailin_enabled_idx');
		}

		return $schema;
	}
}

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
 * Card contacts (#3530): a `kanso_card_contacts` join table linking a card to a
 * Nextcloud Contacts entry for non-user stakeholders, mirroring the assignee
 * join-row shape (`kanso_card_assignees`). READ-ONLY reference - `display_name`
 * is a denormalized snapshot stored at link time (no editing, no sync).
 *
 * Guarded by hasTable so the step is idempotent and safe to re-run.
 */
class Version004200Date20260830000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_card_contacts')) {
			return null;
		}

		$table = $schema->createTable('kanso_card_contacts');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// CardDAV URI of the linked contact (addressbook/vcard reference).
		$table->addColumn('contact_uri', Types::STRING, [
			'notnull' => true,
			'length' => 512,
		]);
		// Denormalized display-name snapshot taken at link time (read-only).
		$table->addColumn('display_name', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		// Named short: oc_kanso_card_contacts (22 chars) overflows the default
		// primary-key name length check, failing install on NC 30-32.
		$table->setPrimaryKey(['id'], 'kanso_ccontact_pk');
		$table->addUniqueIndex(['card_id', 'contact_uri'], 'kanso_card_contact_uniq');

		return $schema;
	}
}

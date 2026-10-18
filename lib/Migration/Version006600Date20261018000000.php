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
 * Card file links (`kanso_card_file_links`, KANSO-307): a LIVE reference from a
 * card to a node in Nextcloud Files, alongside (not instead of) the existing
 * copy-into-app-data attachment flow.
 *
 * Only the file id is authoritative - opening goes to Nextcloud's `/f/<fileid>`
 * permalink, where Files' own ACL decides who may read it. `name` and `mime`
 * are a display snapshot taken at link time so the chip renders from Kanso's
 * own rows with no per-viewer Files lookup; it is the bare filename, never the
 * linker's path (a path would leak the linker's folder layout to the board).
 *
 * One row per (card, file) - re-linking the same file is idempotent.
 *
 * The physical name (`oc_kanso_card_file_links`, 24 chars) is over the NC 30-32
 * primary-key name limit, so the key is named explicitly (PrimaryKeyNameLengthTest).
 *
 * Guarded (hasTable) so the step is idempotent.
 */
class Version006600Date20261018000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, not part of the OCP stubs
	 *  (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_card_file_links')) {
			return null;
		}

		$table = $schema->createTable('kanso_card_file_links');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('file_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('mime', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		$table->addColumn('linked_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id'], 'kanso_cfl_pk');
		// Also serves every per-card read (card_id is its leading column), so no
		// separate card_id index.
		$table->addUniqueIndex(['card_id', 'file_id'], 'kanso_cfl_uniq');

		return $schema;
	}
}

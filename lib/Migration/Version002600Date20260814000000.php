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
 * Projects (#3447): owner-only, cross-board card collections.
 *
 *  - `kanso_projects`: a per-user named collection (title, optional description
 *    and bare-hex color). `owner` is the uid; there is no sharing/ACL table in
 *    v1 - a project is private to its owner, and its card list is additionally
 *    filtered down to the boards the viewer can read at query time.
 *  - `kanso_project_cards`: the flat membership join (project_id, card_id),
 *    unique per pair. A plain card_id index powers the purge cascade and the
 *    "which projects is this card in" lookup on card detail.
 *
 * Guarded (hasTable / hasColumn / hasIndex) so the step is idempotent.
 */
class Version002600Date20260814000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_projects')) {
			$table = $schema->createTable('kanso_projects');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			// Bare hex, no leading '#'.
			$table->addColumn('color', Types::STRING, [
				'notnull' => false,
				'length' => 6,
			]);
			$table->addColumn('owner', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// Unix seconds - a plain int, NOT a DATETIME column.
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner'], 'kanso_projects_owner');
		}

		if (!$schema->hasTable('kanso_project_cards')) {
			$table = $schema->createTable('kanso_project_cards');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('project_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('card_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['project_id', 'card_id'], 'kanso_projcards_uniq');
			$table->addIndex(['card_id'], 'kanso_projcards_card');
		}

		return $schema;
	}
}

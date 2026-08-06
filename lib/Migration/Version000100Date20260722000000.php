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
 * Initial Kanso schema: boards, stacks, cards, labels, card/label and
 * card/assignee relations, board ACL and the per-board change log.
 *
 * `kanso_changes` is the per-board delta-sync cursor log: clients poll with
 * their last seen change id and receive everything newer. Caveat: under
 * concurrent writers rows can commit out of id order (a transaction holding
 * a lower id may commit after one with a higher id has already been read),
 * so pollers may need an overlap window to avoid missing rows. Mitigation is
 * deferred to the delta endpoint card.
 */
class Version000100Date20260722000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('kanso_boards')) {
			$table = $schema->createTable('kanso_boards');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('owner', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('color', Types::STRING, [
				'notnull' => false,
				'length' => 6,
			]);
			$table->addColumn('archived', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('last_modified', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('deleted_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('kanso_stacks')) {
			$table = $schema->createTable('kanso_stacks');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('sort_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('archived', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('deleted_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['board_id'], 'kanso_stacks_board');
		}

		if (!$schema->hasTable('kanso_cards')) {
			$table = $schema->createTable('kanso_cards');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			// Denormalized board_id so board-wide queries (summaries, delta
			// sync) never need to join through kanso_stacks.
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('stack_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('sort_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('duedate', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('done_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('archived', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('owner', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('last_modified', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->addColumn('deleted_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['stack_id', 'sort_key'], 'kanso_cards_stack_sort');
			$table->addIndex(['board_id', 'last_modified'], 'kanso_cards_board_lastmod');
		}

		if (!$schema->hasTable('kanso_labels')) {
			$table = $schema->createTable('kanso_labels');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('color', Types::STRING, [
				'notnull' => false,
				'length' => 6,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['board_id'], 'kanso_labels_board');
		}

		if (!$schema->hasTable('kanso_card_labels')) {
			$table = $schema->createTable('kanso_card_labels');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('card_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('label_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			// Named short defensively: oc_kanso_card_labels (20 chars) is at the
			// edge of the default primary-key name length limit enforced on NC
			// 30-32; an explicit short name keeps install safe across versions.
			$table->setPrimaryKey(['id'], 'kanso_clabel_pk');
			$table->addUniqueIndex(['card_id', 'label_id'], 'kanso_card_labels_uniq');
		}

		if (!$schema->hasTable('kanso_card_assignees')) {
			$table = $schema->createTable('kanso_card_assignees');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('card_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('participant', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// 0 = user, 1 = group
			$table->addColumn('type', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
			// Named short: oc_kanso_card_assignees (23 chars) overflows the default
			// primary-key name length check, failing install on NC 30-32.
			$table->setPrimaryKey(['id'], 'kanso_cassignee_pk');
			$table->addUniqueIndex(['card_id', 'participant', 'type'], 'kanso_card_assign_uniq');
		}

		if (!$schema->hasTable('kanso_board_acl')) {
			$table = $schema->createTable('kanso_board_acl');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			// 0 = user, 1 = group
			$table->addColumn('participant_type', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('participant', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// Permission bitmask
			$table->addColumn('permission', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['board_id'], 'kanso_acl_board');
			$table->addUniqueIndex(['board_id', 'participant_type', 'participant'], 'kanso_acl_uniq');
		}

		if (!$schema->hasTable('kanso_changes')) {
			$table = $schema->createTable('kanso_changes');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('entity_type', Types::SMALLINT, [
				'notnull' => true,
			]);
			$table->addColumn('entity_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('action', Types::SMALLINT, [
				'notnull' => true,
			]);
			$table->addColumn('actor', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
				'unsigned' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['board_id', 'id'], 'kanso_changes_board_id');
		}

		return $schema;
	}
}

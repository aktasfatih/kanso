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
 * Emoji reactions on card comments (#3550): a reaction is one row per
 * (comment, user, emoji) in `kanso_comment_reactions`. A user can add several
 * distinct emoji to the same comment but may not double-react the same emoji -
 * enforced by a NAMED unique index on (comment_id, uid, emoji). A named index
 * on comment_id backs the per-comment aggregation.
 *
 * Reactions are board-scoped by construction: a reaction reaches its board only
 * through comment -> card -> board (resolved + permission-checked at the service
 * layer, mirroring CommentService). The emoji itself is validated against a
 * FIXED allowed set at the service layer, so the column only ever holds a small
 * enumerated value; a short STRING is enough to hold a multi-byte emoji.
 *
 * Reaction mutations append a card-targeted row to `kanso_changes` (like comment
 * mutations) so the board ETag bumps and realtime clients refetch.
 *
 * Guarded by hasTable so the step is idempotent and safe on a fresh install.
 *
 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
 *  stubs (Deck suppresses the same class in its psalm config).
 */
class Version003500Date20260823000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_comment_reactions')) {
			return null;
		}

		$table = $schema->createTable('kanso_comment_reactions');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('comment_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		// A single emoji from the FIXED allowed set (validated at the service
		// layer). 32 chars comfortably holds any multi-codepoint emoji.
		$table->addColumn('emoji', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		// Named explicitly: the physical table name (oc_kanso_comment_reactions,
		// 26 chars) makes the default-derived PK index name exceed Nextcloud's
		// 23-char limit, which fails app install on NC 30-32.
		$table->setPrimaryKey(['id'], 'kanso_creact_pk');
		// A user reacts at most once per emoji per comment - the toggle's
		// idempotency guarantee, enforced in the schema.
		$table->addUniqueIndex(['comment_id', 'uid', 'emoji'], 'kanso_creact_unique');
		// Per-comment aggregation (list + count) reads by comment_id.
		$table->addIndex(['comment_id'], 'kanso_creact_comment');

		return $schema;
	}
}

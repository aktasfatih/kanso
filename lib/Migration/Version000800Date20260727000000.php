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
 * Card comments / discussions (`kanso_comments`): threaded, ONE level deep.
 * A comment belongs to a card and optionally replies to a TOP-LEVEL comment
 * (parent_comment_id); a reply-to-a-reply is rejected at the service layer, so
 * the thread is always exactly two levels (comment + its replies) — the
 * deep-tree trap is avoided by construction.
 *
 * Bodies are markdown, rendered through the same DOMPurify sanitizer as card
 * descriptions on the client (stored raw, never trusted as HTML). Edits stamp
 * edited_at; deletes are soft (deleted_at) and cascade to a top-level comment's
 * replies at the service layer. Comment mutations append a card-targeted row to
 * `kanso_changes` so the board ETag bumps and realtime refetches.
 *
 * Guarded by hasTable so the step is idempotent.
 */
class Version000800Date20260727000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_comments')) {
			return null;
		}

		$table = $schema->createTable('kanso_comments');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('card_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
		]);
		// Null = a top-level comment; otherwise the id of the top-level comment
		// this one replies to (one level only).
		$table->addColumn('parent_comment_id', Types::BIGINT, [
			'notnull' => false,
			'length' => 8,
		]);
		$table->addColumn('author', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('body', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->addColumn('edited_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->addColumn('deleted_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 8,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id']);
		// The thread of a card (list + count) and reply lookup / cascade.
		$table->addIndex(['card_id'], 'kanso_comments_card');
		$table->addIndex(['parent_comment_id'], 'kanso_comments_parent');

		return $schema;
	}
}

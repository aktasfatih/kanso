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
 * Project comments (`kanso_project_comments`): an OWNER-ONLY personal discussion
 * log on a project (#3563). Projects are owner-only today (no sharing table), so
 * a project comment has exactly one reader - the owner - and there is no
 * @mention / notify / watcher machinery (that would need a second reader).
 *
 * This is a deliberately THIN twin of `kanso_comments` (card comments), kept as
 * its own table rather than generalizing `kanso_comments`: the ACL model is
 * entirely different (project ownership vs board permissions), so folding the
 * two would force `kanso_comments.card_id` nullable and thread project ACL
 * through every card-comment query. A separate table leaves the shipped
 * card-comment path completely untouched.
 *
 * Threading is ONE level deep: a comment belongs to a project and optionally
 * replies to a TOP-LEVEL comment (parent_comment_id); a reply-to-a-reply is
 * rejected at the service layer, so the thread is always exactly two levels.
 * Bodies are markdown, stored raw and rendered through the same client-side
 * DOMPurify/markdown-it pipeline as card comments (never trusted as HTML). Edits
 * stamp edited_at; deletes are soft (deleted_at) and cascade to a top-level
 * comment's replies at the service layer.
 *
 * Index/constraint names are globally unique (kanso_pcomments_*) so this step is
 * safe on a fresh install. Guarded by hasTable so the step is idempotent.
 */
class Version003100Date20260819000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass ISchemaWrapper::createTable() is
	 *  docblocked as Doctrine\DBAL\Schema\Table, which is not part of the OCP
	 *  stubs (Deck suppresses the same class in its psalm config).
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('kanso_project_comments')) {
			return null;
		}

		$table = $schema->createTable('kanso_project_comments');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 8,
		]);
		$table->addColumn('project_id', Types::BIGINT, [
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
		$table->setPrimaryKey(['id'], 'kanso_pcomments_pk');
		// The thread of a project (list + count) and reply lookup / cascade.
		$table->addIndex(['project_id'], 'kanso_pcomments_project');
		$table->addIndex(['parent_comment_id'], 'kanso_pcomments_parent');

		return $schema;
	}
}

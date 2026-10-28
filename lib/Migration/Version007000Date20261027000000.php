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
 * AI-actor attribution (#352). A nullable `actor_name` on the three action
 * tables (comment, review, assignee) carries the optional AI name (e.g.
 * "Claude Code") that an MCP session attributes an action to; the human uid
 * (author / reviewer / participant) stays the token owner. A per-board
 * `allow_ai_actors` toggle (OFF by default) gates whether the name may be set.
 */
class Version007000Date20261027000000 extends SimpleMigrationStep {
	/**
	 * @psalm-suppress UndefinedDocblockClass
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('kanso_comments')) {
			$comments = $schema->getTable('kanso_comments');
			if (!$comments->hasColumn('actor_name')) {
				// Optional AI actor name (e.g. "Claude Code") the comment is
				// attributed to; the `author` uid stays the token owner.
				$comments->addColumn('actor_name', Types::STRING, [
					'notnull' => false,
					'length' => 255,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('kanso_card_reviews')) {
			$reviews = $schema->getTable('kanso_card_reviews');
			if (!$reviews->hasColumn('actor_name')) {
				// Optional AI actor name the review request is attributed to.
				$reviews->addColumn('actor_name', Types::STRING, [
					'notnull' => false,
					'length' => 255,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('kanso_card_assignees')) {
			$assignees = $schema->getTable('kanso_card_assignees');
			if (!$assignees->hasColumn('actor_name')) {
				// Optional AI actor name the assignment is attributed to; the
				// `participant` uid stays the token owner.
				$assignees->addColumn('actor_name', Types::STRING, [
					'notnull' => false,
					'length' => 255,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('kanso_boards')) {
			$boards = $schema->getTable('kanso_boards');
			if (!$boards->hasColumn('allow_ai_actors')) {
				// Per-board gate for AI-actor attribution, OFF by default.
				$boards->addColumn('allow_ai_actors', Types::BOOLEAN, [
					'notnull' => false,
				]);
				$changed = true;
			}
			if (!$boards->hasColumn('auto_complete_parent')) {
				// Per-board gate for "all children done → parent done" (#198),
				// OFF by default: a NULL column reads as OFF, so no backfill.
				$boards->addColumn('auto_complete_parent', Types::BOOLEAN, [
					'notnull' => false,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}

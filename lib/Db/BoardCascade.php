<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The board cascade: the declarative map of every board-scoped `kanso_` table,
 * plus the set-based DELETEs that empty them.
 *
 * Kanso's schema declares ZERO foreign keys, so NOTHING cascades for free -
 * a board purge has to name every table explicitly. Keeping that list here, as
 * data rather than as a hand-written sequence of mapper calls, is what makes it
 * testable: BoardCascadeCompletenessTest enumerates every table the migrations
 * create and fails the build when one is neither registered below nor listed in
 * {@see NOT_BOARD_SCOPED}. A new board-scoped table therefore cannot be added
 * without a deliberate decision about what a board purge does with it.
 *
 * Two tables are absent on purpose and handled by their own mappers, because
 * they are the roots of the graph rather than dependents of it:
 *
 *  - the cards table, emptied by the card mapper's deleteByBoard() (the
 *    architecture ratchet keeps that table addressable from that mapper only);
 *  - the boards table itself, whose row goes last via {@see BoardMapper}.
 *
 * All deletes here are set-based and chunk their id lists, so a board with a
 * very large card or change history never builds one unbounded IN (...).
 */
class BoardCascade {
	/**
	 * Ids per IN (...) list. Keeps a single statement short (and well inside
	 * every backend's placeholder limit) on a board with a long history.
	 */
	public const CHUNK_SIZE = 1000;

	/**
	 * Board-scoped tables carrying a literal `board_id`: one flat DELETE each.
	 *
	 * Several of these are ALSO reachable by card id and appear in
	 * {@see BY_CARD_ID} as well - that redundancy is deliberate. A row whose
	 * board_id drifted from its card's board (or vice versa) is still swept, so
	 * the purge cannot leave a half-visible remnant.
	 */
	public const BY_BOARD_ID = [
		'kanso_archive_rules',
		'kanso_automation_rules',
		'kanso_board_acl',
		'kanso_board_group_members',
		'kanso_board_pins',
		'kanso_board_subscriptions',
		'kanso_card_attachments',
		'kanso_card_fields',
		'kanso_card_relations',
		'kanso_card_running_timers',
		'kanso_card_time_entries',
		'kanso_changes',
		'kanso_labels',
		'kanso_mail_intake',
		'kanso_recur_rules',
		'kanso_review_types',
		'kanso_stacks',
	];

	/**
	 * Tables reachable only through the board's CARDS. The value is the list of
	 * columns holding a card id - `kanso_card_relations` has two (a relation is
	 * an edge, and both ends must be swept so a relation stored from the far
	 * side is not left dangling), and a recurrence rule points at its template
	 * card.
	 *
	 * None of these predicates can reach a SURVIVING board's row, which is what
	 * makes them safe on a destructive path: every write that sets one of these
	 * columns rejects a cross-board value up front - a relation
	 * (CardRelationService::addRelation, "Related cards must be on the same
	 * board"), a sub-card parent (CardService::setParent, "Parent card must be
	 * on the same board") and a recurrence rule (RecurrenceService::validate,
	 * "The template card does not belong to the board"). A card that changes
	 * board does so by being re-created on the target and soft-deleted on the
	 * source, so it never drags an edge across the boundary either.
	 *
	 * @var array<string, list<string>>
	 */
	public const BY_CARD_ID = [
		'kanso_card_assignees' => ['card_id'],
		'kanso_card_attachments' => ['card_id'],
		'kanso_card_contacts' => ['card_id'],
		'kanso_card_field_values' => ['card_id'],
		'kanso_card_labels' => ['card_id'],
		'kanso_card_links' => ['card_id'],
		'kanso_card_relations' => ['card_id', 'other_card_id'],
		'kanso_card_reviews' => ['card_id'],
		'kanso_card_running_timers' => ['card_id'],
		'kanso_card_time_entries' => ['card_id'],
		'kanso_checklist_items' => ['card_id'],
		'kanso_comments' => ['card_id'],
		'kanso_project_cards' => ['card_id'],
		'kanso_recur_rules' => ['template_card_id'],
		'kanso_reminders' => ['card_id'],
		'kanso_subscriptions' => ['card_id'],
	];

	/**
	 * Grandchildren: tables keyed by the id of a row in a board-scoped table,
	 * with no board_id and no card_id of their own. They MUST be emptied before
	 * their parent, or the purge strands rows nothing can ever reach again -
	 * the same ordering bug the card trash purge documents for comment
	 * reactions (#3550).
	 *
	 * table => [own id column, parent table, the parent's OWN board/card link]
	 *
	 * The third element is not decoration: it is how the parent ids are looked
	 * up. Leaving it implicit would let a grandchild be registered against a
	 * parent reached by the wrong column, and the lookup would quietly resolve
	 * to an empty id set - a silent no-op that leaves the rows behind, which is
	 * the worst failure mode this registry has.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	public const BY_PARENT_ID = [
		'kanso_comment_reactions' => ['comment_id', 'kanso_comments', 'card_id'],
		'kanso_change_details' => ['change_id', 'kanso_changes', 'board_id'],
		'kanso_mail_seen' => ['intake_id', 'kanso_mail_intake', 'board_id'],
	];

	/**
	 * Tables that are deliberately NOT board-scoped and therefore survive a
	 * board purge. Listed explicitly so the completeness guard passes on
	 * purpose rather than by omission:
	 *
	 *  - kanso_board_groups: a USER's folders of boards (keyed by uid). The
	 *    folder outlives any board filed into it; only the membership rows
	 *    (kanso_board_group_members) are board-scoped.
	 *  - kanso_projects: cross-board project records owned by a user; a project
	 *    spans boards, so only its card links (kanso_project_cards) go.
	 *  - kanso_project_comments: hang off a project, not off a board.
	 */
	public const NOT_BOARD_SCOPED = [
		'kanso_board_groups',
		'kanso_projects',
		'kanso_project_comments',
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Empties every {@see BY_BOARD_ID} table for one board.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByBoardId(int $boardId): int {
		$deleted = 0;
		foreach (self::BY_BOARD_ID as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));
			$deleted += $qb->executeStatement();
		}

		return $deleted;
	}

	/**
	 * Empties every {@see BY_CARD_ID} table for the given card ids.
	 *
	 * @param list<int> $cardIds
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByCardIds(array $cardIds): int {
		$deleted = 0;
		foreach (self::BY_CARD_ID as $table => $columns) {
			foreach ($columns as $column) {
				$deleted += $this->deleteIn($table, $column, $cardIds);
			}
		}

		return $deleted;
	}

	/**
	 * Deletes every row of $table whose $column is in $ids, one chunk of
	 * {@see CHUNK_SIZE} ids per statement. Empty id set → 0 (and no query).
	 *
	 * @internal $table and $column are interpolated into SQL, so they may only
	 *           ever come from this class's registry constants - never from a
	 *           request. This is a board-purge primitive, not a general API.
	 *
	 * @param list<int> $ids
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteIn(string $table, string $column, array $ids): int {
		$deleted = 0;
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$deleted += $qb->executeStatement();
		}

		return $deleted;
	}

	/**
	 * The ids in $column of the rows of $table matching a board id - the lookup
	 * that feeds {@see BY_PARENT_ID} sweeps whose parent is board-scoped.
	 *
	 * @internal registry-supplied identifiers only - see {@see deleteIn}.
	 *
	 * @return list<int>
	 * @throws Exception
	 */
	public function idsByBoardId(string $table, int $boardId, string $column = 'id'): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)
			->from($table)
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		return $this->collectIds($qb, $column);
	}

	/**
	 * The ids in $column of the rows of $table whose $matchColumn is in $ids -
	 * the lookup that feeds {@see BY_PARENT_ID} sweeps whose parent is
	 * card-scoped (comments → reactions).
	 *
	 * @internal registry-supplied identifiers only - see {@see deleteIn}.
	 *
	 * @param list<int> $ids
	 * @return list<int>
	 * @throws Exception
	 */
	public function idsIn(string $table, string $matchColumn, array $ids, string $column = 'id'): array {
		$collected = [];
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($column)
				->from($table)
				->where($qb->expr()->in($matchColumn, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->collectIds($qb, $column) as $id) {
				$collected[] = $id;
			}
		}

		return $collected;
	}

	/**
	 * @return list<int>
	 * @throws Exception
	 */
	private function collectIds(IQueryBuilder $qb, string $column): array {
		$ids = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row[$column];
		}
		$result->closeCursor();

		return $ids;
	}
}

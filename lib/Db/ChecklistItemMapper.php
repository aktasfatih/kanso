<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Service\CardVisibilityScope;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_checklist_items`.
 *
 * @template-extends QBMapper<ChecklistItem>
 */
class ChecklistItemMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private CardVisibilityScope $visibilityScope,
	) {
		parent::__construct($db, 'kanso_checklist_items', ChecklistItem::class);
	}

	/**
	 * @throws DoesNotExistException if the item does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): ChecklistItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All items of a card, in display order.
	 *
	 * Checklist items carry no unique index on their sort key and
	 * {@see \OCA\Kanso\Service\SortKeyService::between()} is deterministic, so two
	 * concurrent moves into the same gap can derive the SAME key. `sort_key` alone
	 * would then leave the tied rows in whatever order the DB returns, which can
	 * flip between reloads; the `id` tiebreaker (same idiom as
	 * {@see self::findOpenAssignedInBoards()}) keeps that order stable.
	 *
	 * @return ChecklistItem[]
	 * @throws Exception
	 */
	public function findByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The last item of a card in display order, or null for an empty
	 * checklist - the append anchor for item creation.
	 *
	 * @throws Exception
	 */
	public function findLastByCard(int $cardId): ?ChecklistItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'DESC')
			->setMaxResults(1);

		$items = $this->findEntities($qb);
		return $items[0] ?? null;
	}

	/**
	 * Per-card checklist progress for every non-deleted card on a board, as a
	 * fixed two-query join through `kanso_cards` - the board payload stays a
	 * constant number of queries no matter how many items exist. Cards with no
	 * items are simply absent from the map (callers default to 0/0).
	 *
	 * The done count uses a dialect-safe boolean parameter (PARAM_BOOL) rather
	 * than SUM(done) so it is correct on Postgres (native boolean) as well as
	 * MySQL/SQLite (0/1).
	 *
	 * @return array<int, array{total: int, done: int}> map of cardId => counts
	 * @throws Exception
	 */
	public function progressByBoard(int $boardId, ViewerContext $viewer): array {
		$totals = $this->countByBoard($boardId, false, $viewer);
		$done = $this->countByBoard($boardId, true, $viewer);

		$map = [];
		foreach ($totals as $cardId => $count) {
			$map[$cardId] = ['total' => $count, 'done' => $done[$cardId] ?? 0];
		}
		return $map;
	}

	/**
	 * The anonymous-share twin of {@see self::progressByBoard()} (#3743): the
	 * same per-card progress map, restricted to PUBLIC cards only - the
	 * public snapshot has no viewer and must never count a hidden card's
	 * items. The map is keyed by card id and consumed against the (equally
	 * public-only) card list, so the restriction is belt-and-braces.
	 *
	 * @return array<int, array{total: int, done: int}> map of cardId => counts
	 * @throws Exception
	 */
	public function progressByBoardPublicOnly(int $boardId): array {
		$totals = $this->countByBoardPublicOnly($boardId, false);
		$done = $this->countByBoardPublicOnly($boardId, true);

		$map = [];
		foreach ($totals as $cardId => $count) {
			$map[$cardId] = ['total' => $count, 'done' => $done[$cardId] ?? 0];
		}
		return $map;
	}

	/**
	 * Item counts grouped by card for a board's PUBLIC cards only - the
	 * anonymous half of {@see self::countByBoard()}.
	 *
	 * @return array<int, int> map of cardId => count
	 * @throws Exception
	 */
	private function countByBoardPublicOnly(int $boardId, bool $doneOnly): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ci.card_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 'ci')
			->innerJoin('ci', 'kanso_cards', 'c', $qb->expr()->eq('ci.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('ci.card_id');
		$this->visibilityScope->applyPublicOnly($qb, 'c');

		if ($doneOnly) {
			$qb->andWhere($qb->expr()->eq('ci.done', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['card_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * The project-analytics twin of {@see self::progressByBoard()} - per-card
	 * checklist progress over an explicit card id set instead of a board, as two
	 * grouped queries. The caller supplies ONLY ACL-resolved project card ids
	 * (see {@see \OCA\Kanso\Db\ProjectCardMapper::findCardsInProjectAndBoards}),
	 * so there is no board scope and no cross-board leak. An empty set yields an
	 * empty map.
	 *
	 * @param int[] $cardIds the viewer's ACL-resolved project card ids (empty → [])
	 * @return array<int, array{total: int, done: int}> map of cardId => counts
	 * @throws Exception
	 */
	public function progressByCards(array $cardIds): array {
		if ($cardIds === []) {
			return [];
		}

		$totals = $this->countByCards($cardIds, false);
		$done = $this->countByCards($cardIds, true);

		$map = [];
		foreach ($totals as $cardId => $count) {
			$map[$cardId] = ['total' => $count, 'done' => $done[$cardId] ?? 0];
		}
		return $map;
	}

	/**
	 * Item counts grouped by card for an explicit card id set, optionally
	 * restricted to done items. Assumes a non-empty set (the caller guards).
	 *
	 * @param int[] $cardIds
	 * @return array<int, int> map of cardId => count
	 * @throws Exception
	 */
	private function countByCards(array $cardIds, bool $doneOnly): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ci.card_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 'ci')
			->innerJoin('ci', 'kanso_cards', 'c', $qb->expr()->eq('ci.card_id', 'c.id'))
			->where($qb->expr()->in('c.id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('ci.card_id');

		if ($doneOnly) {
			$qb->andWhere($qb->expr()->eq('ci.done', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['card_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Item counts grouped by card for a board, optionally restricted to done
	 * items.
	 *
	 * @return array<int, int> map of cardId => count
	 * @throws Exception
	 */
	private function countByBoard(int $boardId, bool $doneOnly, ViewerContext $viewer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ci.card_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName(), 'ci')
			->innerJoin('ci', 'kanso_cards', 'c', $qb->expr()->eq('ci.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('ci.card_id');
		$this->visibilityScope->applyForViewer($qb, 'c', $viewer);

		if ($doneOnly) {
			$qb->andWhere($qb->expr()->eq('ci.done', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['card_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * The derived "waiting on client" aggregate (epic 6, #3746): for every
	 * non-deleted card on the board, whether at least one OPEN step is parked
	 * on the given board side and since when - cardId => MIN(assigned_at)
	 * over the card's open steps whose FROZEN role equals $role. Presence in
	 * the map IS the wait flag; the value is the oldest such step's
	 * assignment time (the "since" the tile chip shows).
	 *
	 * ONE fixed grouped query folded into the board summary as another
	 * enrichment map next to {@see self::progressByBoard()} - never a
	 * per-card lookup and never a stored column (the wait state is computed
	 * from step state alone, so it can not drift from the truth). The open
	 * filter binds a dialect-safe PARAM_BOOL exactly like progressByBoard
	 * (Postgres native boolean vs MySQL/SQLite 0/1).
	 *
	 * Viewer-scoped like every other summary map (#3743): a card hidden from
	 * this viewer never appears, so its wait state can not leak through the
	 * map even if a caller were to emit it wholesale.
	 *
	 * The primitive is role-agnostic: 'external' (the default) derives
	 * "waiting on client"; binding the viewer's own side instead would derive
	 * the mirror "waiting on us" for free.
	 *
	 * @return array<int, ?int> map of cardId => oldest open matching step's assigned_at
	 * @throws Exception
	 */
	public function waitingByBoard(int $boardId, ViewerContext $viewer, string $role = ViewerContext::ROLE_EXTERNAL): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ci.card_id')
			->selectAlias($qb->func()->min('ci.assigned_at'), 'waiting_since')
			->from($this->getTableName(), 'ci')
			->innerJoin('ci', 'kanso_cards', 'c', $qb->expr()->eq('ci.card_id', 'c.id'))
			->where($qb->expr()->eq('c.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ci.done', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('ci.assigned_role', $qb->createNamedParameter($role)))
			->groupBy('ci.card_id');
		$this->visibilityScope->applyForViewer($qb, 'c', $viewer);

		$result = $qb->executeQuery();
		$map = [];
		while (($row = $result->fetch()) !== false) {
			$map[(int)$row['card_id']] = $row['waiting_since'] !== null ? (int)$row['waiting_since'] : null;
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * The cross-board "my steps" feed (#3745): every OPEN checklist step
	 * assigned to $uid on the given boards, joined up with its card / board /
	 * stack titles for display. ONE query, driven by the (assigned_user, done)
	 * index; the card-visibility scope is applied in cross-board mode exactly
	 * like {@see CardMapper::findAssignedInBoards} (my-cards) - a step of a
	 * card the viewer cannot SEE is never returned, no matter that they are
	 * assigned to it (assignment grants no visibility). Deleted and archived
	 * cards drop out like everywhere else.
	 *
	 * Ordered due-date-first (undated steps last), then by item id for a
	 * stable tail.
	 *
	 * @param int[] $boardIds the viewer's readable board set
	 * @param array<int, string> $rolesByBoard the viewer's effective role per
	 *                                         board id, from {@see \OCA\Kanso\Access\BoardAccess::rolesFor()}
	 * @return list<array<string, mixed>>
	 * @throws Exception
	 */
	public function findOpenAssignedInBoards(string $uid, array $boardIds, array $rolesByBoard, int $limit = 200): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('ci.id', 'ci.card_id', 'ci.title', 'ci.due_date', 'ci.assigned_at', 'ci.assigned_role')
			->addSelect('c.board_id', 'c.stack_id')
			->selectAlias('c.title', 'card_title')
			->selectAlias('b.title', 'board_title')
			->selectAlias('s.title', 'stack_title')
			->from($this->getTableName(), 'ci')
			->innerJoin('ci', 'kanso_cards', 'c', $qb->expr()->eq('ci.card_id', 'c.id'))
			->innerJoin('c', 'kanso_boards', 'b', $qb->expr()->eq('c.board_id', 'b.id'))
			->leftJoin('c', 'kanso_stacks', 's', $qb->expr()->eq('c.stack_id', 's.id'))
			->where($qb->expr()->eq('ci.assigned_user', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('ci.done', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->in('c.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			// Undated steps last: a step with no due date sorts after any dated one.
			->addOrderBy($qb->createFunction('CASE WHEN ci.due_date IS NULL THEN 1 ELSE 0 END'), 'ASC')
			->addOrderBy('ci.due_date', 'ASC')
			->addOrderBy('ci.id', 'ASC')
			->setMaxResults($limit);
		$this->visibilityScope->apply($qb, 'c', $uid, null, $rolesByBoard);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$due = $row['due_date'] !== null
				? (new \DateTime((string)$row['due_date']))->format(\DateTimeInterface::ATOM)
				: null;
			$rows[] = [
				'id' => (int)$row['id'],
				'cardId' => (int)$row['card_id'],
				'cardTitle' => (string)$row['card_title'],
				'boardId' => (int)$row['board_id'],
				'boardTitle' => (string)$row['board_title'],
				'stackTitle' => $row['stack_title'] !== null ? (string)$row['stack_title'] : null,
				'title' => (string)$row['title'],
				'dueDate' => $due,
				'assignedAt' => $row['assigned_at'] !== null ? (int)$row['assigned_at'] : null,
				'assignedRole' => $row['assigned_role'] !== null ? (string)$row['assigned_role'] : null,
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Hard-deletes every checklist item of a card - cascade for a card purge.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByCard(int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}

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
	 * @return ChecklistItem[]
	 * @throws Exception
	 */
	public function findByCard(int $cardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_key', 'ASC');

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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Service\CardVisibilityScope;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_changes`, the per-board delta-sync change log.
 *
 * @template-extends QBMapper<Change>
 */
class ChangeMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private CardVisibilityScope $visibilityScope,
	) {
		parent::__construct($db, 'kanso_changes', Change::class);
	}

	/**
	 * Append one entry to a board's change log.
	 *
	 * @param int $entityType one of the Change::ENTITY_* constants
	 * @param int $action one of the Change::ACTION_* constants
	 * @param string|null $actor uid of the acting user, null for system actions
	 * @param int $createdAt unix timestamp of the change
	 * @param int|null $verb one of the Change::VERB_* constants, or null (generic)
	 * @return Change the inserted entry with its id set
	 * @throws Exception
	 */
	public function insertChange(int $boardId, int $entityType, int $entityId, int $action, ?string $actor, int $createdAt, ?int $verb = null): Change {
		$change = new Change();
		$change->setBoardId($boardId);
		$change->setEntityType($entityType);
		$change->setEntityId($entityId);
		$change->setAction($action);
		$change->setActor($actor);
		$change->setVerb($verb);
		$change->setCreatedAt($createdAt);

		return $this->insert($change);
	}

	/**
	 * A single entity's change rows, newest first, capped - the source for the
	 * per-card Activity feed. Uses the (entity_type, entity_id) index.
	 *
	 * @param int $entityType one of the Change::ENTITY_* constants
	 * @return Change[]
	 * @throws Exception
	 */
	public function findByEntity(int $boardId, int $entityType, int $entityId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Recent card-status change rows for the Inbox follow-feed: the given verbs,
	 * on the followed cards, restricted to the viewer's readable board set,
	 * excluding the viewer's own actions, newest first. Mirrors
	 * {@see \OCA\Kanso\Db\CommentMapper::findInboxForCards()} - the caller supplies
	 * both the followed-card set and the ACL-filtered readable board set, so no
	 * per-row permission check is needed. The `author` key holds the actor uid so
	 * the row merges cleanly with the comment feed. Empty card/board/verb set → [].
	 * Uses the (entity_type, entity_id) index.
	 *
	 * Visibility (#3743): the viewer is $excludeActor - the scope runs on the
	 * joined card row (a change row alone carries no title, but the feed
	 * enriches it with one, so the JOINED card must be viewer-visible).
	 *
	 * @param int[] $cardIds followed card ids
	 * @param int[] $boardIds the viewer's readable board ids
	 * @param int[] $verbs the Change::VERB_* values to surface
	 * @param array<int, string> $rolesByBoard the viewer's role per board id
	 * @return list<array{id: int, cardId: int, boardId: int, cardTitle: string, boardTitle: string, author: string, verb: int, createdAt: int}>
	 * @throws Exception
	 */
	public function findInboxForCards(array $cardIds, array $boardIds, string $excludeActor, array $verbs, int $limit, array $rolesByBoard): array {
		if ($cardIds === [] || $boardIds === [] || $verbs === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('ch.id', 'ch.actor', 'ch.verb', 'ch.created_at')
			->selectAlias('ch.entity_id', 'card_id')
			->addSelect('c.board_id')
			->selectAlias('c.title', 'card_title')
			->selectAlias('b.title', 'board_title')
			->from($this->getTableName(), 'ch')
			->innerJoin('ch', 'kanso_cards', 'c', $qb->expr()->eq('ch.entity_id', 'c.id'))
			->innerJoin('c', 'kanso_boards', 'b', $qb->expr()->eq('c.board_id', 'b.id'))
			->where($qb->expr()->eq('ch.entity_type', $qb->createNamedParameter(Change::ENTITY_CARD, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('ch.entity_id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->in('c.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->in('ch.verb', $qb->createNamedParameter($verbs, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('ch.actor', $qb->createNamedParameter($excludeActor)))
			// Sort by created_at (id as tiebreak) to match CommentMapper's inbox
			// query, so the SQL-side cap and the PHP-side merge in InboxService
			// agree on the "newest first" dimension.
			->orderBy('ch.created_at', 'DESC')
			->addOrderBy('ch.id', 'DESC')
			->setMaxResults($limit);
		$this->visibilityScope->apply($qb, 'c', $excludeActor, null, $rolesByBoard);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'id' => (int)$row['id'],
				'cardId' => (int)$row['card_id'],
				'boardId' => (int)$row['board_id'],
				'cardTitle' => (string)$row['card_title'],
				'boardTitle' => (string)$row['board_title'],
				'author' => (string)$row['actor'],
				'verb' => (int)$row['verb'],
				'createdAt' => (int)$row['created_at'],
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Highest change id of a board - the board's sync cursor and ETag
	 * source. 0 for boards without any change rows (which regular flows
	 * never produce: board creation itself writes the first row, and
	 * pruning always retains each board's newest row - see
	 * {@see ChangeMapper::findPrunableIds()}).
	 *
	 * @throws Exception
	 */
	public function getLatestChangeId(int $boardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($max) ? (int)$max : 0;
	}

	/**
	 * Lowest change id of a board - the oldest RETAINED change, the floor of the
	 * delta window. A client whose cursor is below this floor has fallen off the
	 * pruned tail and must resync (full refetch) rather than receive an
	 * incomplete delta. 0 for boards without any change rows (which regular flows
	 * never produce - see {@see self::getLatestChangeId()}).
	 *
	 * @throws Exception
	 */
	public function getOldestChangeId(int $boardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->min('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$min = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($min) ? (int)$min : 0;
	}

	/**
	 * The board's change rows newer than $since (id > $since), oldest first,
	 * capped at $limit - the delta-sync read behind `GET /api/boards/{id}/changes`.
	 * Uses the (board_id, id) index (WHERE board_id = ? AND id > ? ORDER BY id).
	 * When the returned count equals $limit the window is saturated: the caller
	 * treats that as "client too far behind" and forces a resync rather than
	 * shipping a partial delta.
	 *
	 * @return Change[]
	 * @throws Exception
	 */
	public function findSince(int $boardId, int $since, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Ids of change rows eligible for pruning: older than $olderThan, but
	 * NEVER a board's newest row - deleting that would regress
	 * getLatestChangeId() to 0 for idle boards, flipping their ETag to "0"
	 * and forcing a spurious full refetch on the next poll.
	 *
	 * @return int[] at most $limit ids, oldest first
	 * @throws Exception
	 */
	public function findPrunableIds(int $olderThan, int $limit): array {
		$sub = $this->db->getQueryBuilder();
		$sub->select($sub->func()->max('id'))
			->from($this->getTableName())
			->groupBy('board_id');

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->createFunction('id NOT IN (' . $sub->getSQL() . ')'))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $ids;
	}

	/**
	 * @param int[] $ids
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByIds(array $ids): int {
		if ($ids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
	}
}

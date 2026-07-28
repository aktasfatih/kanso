<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

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
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_changes', Change::class);
	}

	/**
	 * Append one entry to a board's change log.
	 *
	 * @param int $entityType one of the Change::ENTITY_* constants
	 * @param int $action one of the Change::ACTION_* constants
	 * @param string|null $actor uid of the acting user, null for system actions
	 * @param int $createdAt unix timestamp of the change
	 * @return Change the inserted entry with its id set
	 * @throws Exception
	 */
	public function insertChange(int $boardId, int $entityType, int $entityId, int $action, ?string $actor, int $createdAt): Change {
		$change = new Change();
		$change->setBoardId($boardId);
		$change->setEntityType($entityType);
		$change->setEntityId($entityId);
		$change->setAction($action);
		$change->setActor($actor);
		$change->setCreatedAt($createdAt);

		return $this->insert($change);
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

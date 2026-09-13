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
 * Mapper for `kanso_change_details`, the before/after side table of the change
 * log. One row per detailed change (currently only description edits); joined by
 * `change_id` when the per-card Activity feed renders a from → to diff.
 *
 * @template-extends QBMapper<ChangeDetail>
 */
class ChangeDetailMapper extends QBMapper {
	/**
	 * Ids per IN (...) list on the delete paths: keeps a single statement short
	 * and well inside every backend's placeholder limit (SQLite's default is
	 * 999 bound variables on builds older than 3.32).
	 *
	 * Derived from {@see BoardCascade::CHUNK_SIZE} rather than restating its
	 * value, so the two can never drift apart.
	 */
	public const DELETE_CHUNK_SIZE = BoardCascade::CHUNK_SIZE;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_change_details', ChangeDetail::class);
	}

	/**
	 * Store the before/after text for a change row.
	 *
	 * @param int $changeId the `kanso_changes.id` this detail belongs to
	 * @return ChangeDetail the inserted row with its id set
	 * @throws Exception
	 */
	public function insertDetail(int $changeId, ?string $fromText, ?string $toText): ChangeDetail {
		$detail = new ChangeDetail();
		$detail->setChangeId($changeId);
		$detail->setFromText($fromText);
		$detail->setToText($toText);

		return $this->insert($detail);
	}

	/**
	 * Deletes the detail rows of the given change rows. Called by
	 * {@see ChangeMapper::deleteByIds()} BEFORE the parent rows go, because a
	 * detail row carries neither a board id nor a card id: once its parent is
	 * gone the row is unreachable by every purge path there is (the board
	 * cascade finds details only through `kanso_changes`), and nothing would
	 * ever collect it.
	 *
	 * Scoped strictly to the supplied change ids, in chunks of
	 * {@see self::DELETE_CHUNK_SIZE}, so a detail belonging to a change row that
	 * is NOT being pruned is never touched. Empty input → 0 (and no query).
	 *
	 * @param int[] $changeIds
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByChangeIds(array $changeIds): int {
		return $this->deleteWhereIn('change_id', $changeIds);
	}

	/**
	 * Ids of ALREADY-orphaned detail rows: rows whose parent `kanso_changes` row
	 * no longer exists. Capped at $limit and ordered by id so the one-off
	 * cleanup ({@see \OCA\Kanso\Cron\PurgeOrphanChangeDetails}) can walk them in
	 * bounded batches.
	 *
	 * This is an anti-join over the whole side table, so it is deliberately NOT
	 * something any recurring job runs: it exists to drain the backlog that the
	 * change-log prune left behind before it deleted details with their parents,
	 * and the job that uses it stops for good once the scan comes back empty.
	 *
	 * @return int[] at most $limit ids, oldest first
	 * @throws Exception
	 */
	public function findOrphanIds(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.id')
			->from($this->getTableName(), 'd')
			->leftJoin('d', 'kanso_changes', 'c', $qb->expr()->eq('d.change_id', 'c.id'))
			->where($qb->expr()->isNull('c.id'))
			->orderBy('d.id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Deletes detail rows by their OWN id - the second half of the orphan
	 * cleanup, chunked like {@see self::deleteByChangeIds()}. Empty input → 0.
	 *
	 * @param int[] $ids
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByIds(array $ids): int {
		return $this->deleteWhereIn('id', $ids);
	}

	/**
	 * `DELETE FROM kanso_change_details WHERE $column IN (...)`, one chunk of
	 * {@see self::DELETE_CHUNK_SIZE} ids per statement. Empty id set → 0 (and no
	 * query at all).
	 *
	 * @internal $column is interpolated into SQL and may only ever be a literal
	 *           from this class - never request input.
	 *
	 * @param int[] $ids
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	private function deleteWhereIn(string $column, array $ids): int {
		$deleted = 0;
		foreach (array_chunk(array_values($ids), self::DELETE_CHUNK_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$deleted += $qb->executeStatement();
		}

		return $deleted;
	}

	/**
	 * Batch-load the details for a set of change ids in ONE query (IN clause),
	 * keyed by change id for O(1) attachment in the caller. Empty input → [].
	 * Uses the `kanso_chdet_change_idx` index.
	 *
	 * @param int[] $changeIds
	 * @return array<int, ChangeDetail> map of change id → its detail row
	 * @throws Exception
	 */
	public function findByChangeIds(array $changeIds): array {
		if ($changeIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('change_id', $qb->createNamedParameter($changeIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$map = [];
		foreach ($this->findEntities($qb) as $detail) {
			$map[$detail->getChangeId()] = $detail;
		}

		return $map;
	}
}

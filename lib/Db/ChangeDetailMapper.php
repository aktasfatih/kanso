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

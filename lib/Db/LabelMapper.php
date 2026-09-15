<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `kanso_labels`.
 *
 * @template-extends QBMapper<Label>
 */
class LabelMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_labels', Label::class);
	}

	/**
	 * @throws DoesNotExistException if the label does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): Label {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All labels of a board in creation order.
	 *
	 * @return Label[]
	 * @throws Exception
	 */
	public function findByBoard(int $boardId): array {
		return $this->findByBoards([$boardId])[$boardId] ?? [];
	}

	/**
	 * The BOARD-SET twin of {@see self::findByBoard()} (#10298): the labels of
	 * MANY boards in ONE query, for the cross-board Views feed - whose label
	 * union must not cost one query per readable board. Grouped BY BOARD (label
	 * ids are only unique per board) and each group still in creation order, so
	 * a caller walking its board list emits exactly the sequence the per-board
	 * loop did. Boards with no labels are absent from the map.
	 *
	 * @param int[] $boardIds
	 * @return array<int, Label[]> map of boardId => labels in creation order
	 * @throws Exception
	 */
	public function findByBoards(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');

		$map = [];
		foreach ($this->findEntities($qb) as $label) {
			$map[(int)$label->getBoardId()][] = $label;
		}

		return $map;
	}
}

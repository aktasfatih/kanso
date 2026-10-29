<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<CardMoveAlias> */
class CardMoveAliasMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_card_move_aliases', CardMoveAlias::class);
	}

	public function findByOldId(int $id): ?CardMoveAlias {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('old_card_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function record(int $oldId, int $targetId): void {
		$alias = $this->findByOldId($oldId);
		if ($alias === null) {
			$alias = new CardMoveAlias();
			$alias->setOldCardId($oldId);
			$alias->setTargetCardId($targetId);
			$this->insert($alias);
			return;
		}
		$alias->setTargetCardId($targetId);
		$this->update($alias);
	}

	public function deleteByOldId(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('old_card_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}

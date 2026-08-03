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
 * Mapper for `kanso_comment_reactions`.
 *
 * @template-extends QBMapper<CommentReaction>
 */
class CommentReactionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_comment_reactions', CommentReaction::class);
	}

	/**
	 * Whether the given user has already reacted to a comment with an emoji -
	 * the toggle's "does this reaction already exist" check.
	 *
	 * @throws Exception
	 */
	public function exists(int $commentId, string $uid, string $emoji): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('emoji', $qb->createNamedParameter($emoji)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * Removes a single (comment, uid, emoji) reaction. Idempotent: deleting a
	 * reaction that is not there affects zero rows and is a no-op.
	 *
	 * @return int number of deleted rows (0 or 1)
	 * @throws Exception
	 */
	public function deleteReaction(int $commentId, string $uid, string $emoji): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('emoji', $qb->createNamedParameter($emoji)));

		return $qb->executeStatement();
	}

	/**
	 * All reactions on the given comments, so the controller can fold them into
	 * per-comment / per-emoji summaries in one query for a whole thread. Ordered
	 * so the reactor list is stable (oldest reactor first). Empty id set → [].
	 *
	 * @param int[] $commentIds
	 * @return array<int, array{commentId: int, uid: string, emoji: string}>
	 * @throws Exception
	 */
	public function findByComments(array $commentIds): array {
		if ($commentIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('comment_id', 'uid', 'emoji')
			->from($this->getTableName())
			->where($qb->expr()->in('comment_id', $qb->createNamedParameter($commentIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = [
				'commentId' => (int)$row['comment_id'],
				'uid' => (string)$row['uid'],
				'emoji' => (string)$row['emoji'],
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Hard-deletes every reaction on the given comments - cascade for a card /
	 * comment purge. Empty id set → 0.
	 *
	 * @param int[] $commentIds
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByComments(array $commentIds): int {
		if ($commentIds === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('comment_id', $qb->createNamedParameter($commentIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qb->executeStatement();
	}
}

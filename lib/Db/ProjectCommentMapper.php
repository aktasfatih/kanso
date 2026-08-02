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
 * Mapper for `kanso_project_comments`. A thin twin of {@see CommentMapper}, but
 * keyed on `project_id` instead of `card_id`. No board joins - a project comment
 * is owner-only metadata (the owner gate lives in
 * {@see \OCA\Kanso\Service\ProjectCommentService}).
 *
 * @template-extends QBMapper<ProjectComment>
 */
class ProjectCommentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'kanso_project_comments', ProjectComment::class);
	}

	/**
	 * @throws DoesNotExistException if the comment does not exist
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): ProjectComment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * All non-deleted comments of a project, oldest first - the flat thread the
	 * client nests by parent_comment_id.
	 *
	 * @return ProjectComment[]
	 * @throws Exception
	 */
	public function findByProject(int $projectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Soft-deletes every non-deleted reply of a top-level comment in one
	 * set-based UPDATE - the cascade for a thread delete. Doing it as a single
	 * statement (rather than read-then-update-each) closes the window where a
	 * reply inserted concurrently with the parent's delete would survive as an
	 * orphan, and avoids N round-trips.
	 *
	 * @throws Exception
	 */
	public function softDeleteRepliesOf(int $parentCommentId, int $deletedAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('deleted_at', $qb->createNamedParameter($deletedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('parent_comment_id', $qb->createNamedParameter($parentCommentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Hard-deletes every comment of a project (all threads) - cascade for a
	 * project delete.
	 *
	 * @return int number of deleted rows
	 * @throws Exception
	 */
	public function deleteByProject(int $projectId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}

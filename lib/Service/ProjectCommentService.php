<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\ProjectAccess;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectComment;
use OCA\Kanso\Db\ProjectCommentMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Project comments / discussion: a one-level thread (top-level comments
 * and their replies), mirroring {@see CommentService} for cards. Anyone who can
 * read the project reads the thread; posting needs EDIT, as on a card. Authors
 * edit and delete their own comments, and the owner may delete any.
 *
 * There is no @mention / notify / watcher machinery and no `kanso_changes` row
 * (a project has no per-board consumer, see {@see ProjectService}).
 *
 * Deleting a top-level comment cascades a soft-delete over its replies so no
 * reply is orphaned. Bodies are stored raw (markdown) and MUST be rendered
 * through the client DOMPurify/markdown-it sanitizer - never trusted as HTML.
 */
class ProjectCommentService {
	private const MAX_BODY_LENGTH = 10000;

	public function __construct(
		private ProjectCommentMapper $commentMapper,
		private ProjectMapper $projectMapper,
		private ProjectAccess $projectAccess,
	) {
	}

	/**
	 * The project's flat thread (non-deleted, oldest first).
	 *
	 * @return ProjectComment[]
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor cannot read the project
	 */
	public function listForProject(int $projectId, string $actorUid): array {
		$this->loadProject($projectId, $actorUid, PermissionService::PERMISSION_READ);

		return $this->commentMapper->findByProject($projectId);
	}

	/**
	 * Posts a comment ($parentCommentId null) or a reply to a top-level comment.
	 *
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor cannot edit the project
	 * @throws InvalidInputException if the body is empty/too long, or the parent is invalid (missing, other project, deleted, or itself a reply - one level only)
	 */
	public function addComment(int $projectId, string $body, ?int $parentCommentId, string $actorUid): ProjectComment {
		$body = $this->normalizeBody($body);
		$this->loadProject($projectId, $actorUid, PermissionService::PERMISSION_EDIT);

		if ($parentCommentId !== null) {
			$parent = $this->loadParentComment($parentCommentId);
			if ($parent->getProjectId() !== $projectId) {
				throw new InvalidInputException('Parent comment does not exist');
			}
			if ($parent->getParentCommentId() !== null) {
				throw new InvalidInputException('Replies can only be one level deep');
			}
		}

		$comment = new ProjectComment();
		$comment->setProjectId($projectId);
		$comment->setParentCommentId($parentCommentId);
		$comment->setAuthor($actorUid);
		$comment->setBody($body);
		$comment->setCreatedAt(time());
		$comment->setEditedAt(0);
		$comment->setDeletedAt(0);

		return $this->commentMapper->insert($comment);
	}

	/**
	 * Edits a comment body. Only its author may edit, and only while they can
	 * still edit the project. Stamps edited_at.
	 *
	 * @throws DoesNotExistException if the comment or project does not exist or is deleted
	 * @throws NotPermittedException if the actor is not the author or cannot edit the project
	 * @throws InvalidInputException if the body is empty or too long
	 */
	public function editComment(int $commentId, string $body, string $actorUid): ProjectComment {
		$body = $this->normalizeBody($body);
		$comment = $this->loadComment($commentId);
		$this->loadProject($comment->getProjectId(), $actorUid, PermissionService::PERMISSION_EDIT);

		if ($comment->getAuthor() !== $actorUid) {
			throw new NotPermittedException('Only the author may edit this comment');
		}

		if ($body === $comment->getBody()) {
			return $comment;
		}

		$comment->setBody($body);
		$comment->setEditedAt(time());

		return $this->commentMapper->update($comment);
	}

	/**
	 * Soft-deletes a comment. The owner may delete any comment; an author may
	 * delete their own while they can edit the project, unless that would take
	 * other people's replies with it. Deleting a top-level comment cascades to
	 * its non-deleted replies so the thread disappears as a unit.
	 *
	 * @throws DoesNotExistException if the comment or project does not exist or is deleted
	 * @throws NotPermittedException if the actor is neither the owner nor an author who can
	 *                               edit, or the thread holds other people's replies
	 */
	public function deleteComment(int $commentId, string $actorUid): void {
		$comment = $this->loadComment($commentId);
		$project = $this->projectMapper->find($comment->getProjectId());
		if ($project->getOwner() !== $actorUid) {
			$this->projectAccess->assertPermission($project, $actorUid, PermissionService::PERMISSION_EDIT);
			if ($comment->getAuthor() !== $actorUid) {
				throw new NotPermittedException('Only the author may delete this comment');
			}
			if ($comment->getParentCommentId() === null && $this->commentMapper->hasRepliesFromOthers($commentId, $actorUid)) {
				throw new NotPermittedException('Only the project owner may delete a comment other people replied to');
			}
		}

		$now = time();

		// Cascade over a top-level comment's replies so none is orphaned. One
		// statement covers the replies.
		if ($comment->getParentCommentId() === null) {
			$this->commentMapper->softDeleteRepliesOf($commentId, $now);
		}

		$comment->setDeletedAt($now);
		$this->commentMapper->update($comment);
	}

	/**
	 * @throws InvalidInputException if the body is empty or too long
	 */
	private function normalizeBody(string $body): string {
		$body = trim($body);
		if ($body === '') {
			throw new InvalidInputException('Comment body must not be empty');
		}
		if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
			throw new InvalidInputException('Comment body is too long');
		}
		return $body;
	}

	/**
	 * @throws DoesNotExistException if the comment does not exist or is deleted
	 */
	private function loadComment(int $id): ProjectComment {
		$comment = $this->commentMapper->find($id);
		if ($comment->getDeletedAt() > 0) {
			throw new DoesNotExistException('Project comment ' . $id . ' is deleted');
		}
		return $comment;
	}

	/**
	 * @throws InvalidInputException if the parent comment is missing or deleted
	 */
	private function loadParentComment(int $id): ProjectComment {
		try {
			$parent = $this->commentMapper->find($id);
		} catch (DoesNotExistException) {
			throw new InvalidInputException('Parent comment does not exist');
		}
		if ($parent->getDeletedAt() > 0) {
			throw new InvalidInputException('Parent comment does not exist');
		}
		return $parent;
	}

	/**
	 * @param int $permission bitmask of PermissionService::PERMISSION_* bits
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor lacks a required bit
	 */
	private function loadProject(int $id, string $uid, int $permission): Project {
		$project = $this->projectMapper->find($id);
		$this->projectAccess->assertPermission($project, $uid, $permission);
		return $project;
	}
}

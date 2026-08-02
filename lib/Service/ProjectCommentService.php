<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectComment;
use OCA\Kanso\Db\ProjectCommentMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Project comments / discussion - an OWNER-ONLY personal log on a project
 * (#3563). A one-level thread (top-level comments + their replies, no deeper
 * nesting), mirroring {@see CommentService} for cards but with a completely
 * different ACL: projects are owner-only (no sharing table), so EVERY operation
 * asserts the actor IS the project owner and a project comment has exactly one
 * reader. There is deliberately NO @mention / notify / watcher machinery - there
 * is no second reader to notify - and no `kanso_changes` row (a project is
 * per-user, cross-board metadata with no per-board consumer, exactly as
 * {@see ProjectService} documents for project membership).
 *
 * A comment may be edited only by its author (== the owner) and deleted by its
 * author. Deleting a top-level comment cascades a soft-delete over its replies
 * so no reply is orphaned. Bodies are stored raw (markdown) and MUST be rendered
 * through the client DOMPurify/markdown-it sanitizer - never trusted as HTML.
 */
class ProjectCommentService {
	private const MAX_BODY_LENGTH = 10000;

	public function __construct(
		private ProjectCommentMapper $commentMapper,
		private ProjectMapper $projectMapper,
	) {
	}

	/**
	 * The project's flat thread (non-deleted, oldest first). Owner only.
	 *
	 * @return ProjectComment[]
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 */
	public function listForProject(int $projectId, string $actorUid): array {
		$this->loadOwnedProject($projectId, $actorUid);

		return $this->commentMapper->findByProject($projectId);
	}

	/**
	 * Posts a comment ($parentCommentId null) or a reply to a top-level comment.
	 * Owner only.
	 *
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor is not the owner
	 * @throws InvalidInputException if the body is empty/too long, or the parent is invalid (missing, other project, deleted, or itself a reply - one level only)
	 */
	public function addComment(int $projectId, string $body, ?int $parentCommentId, string $actorUid): ProjectComment {
		$body = $this->normalizeBody($body);
		$this->loadOwnedProject($projectId, $actorUid);

		if ($parentCommentId !== null) {
			$parent = $this->loadParentComment($parentCommentId);
			if ($parent->getProjectId() !== $projectId) {
				throw new InvalidInputException('Parent comment belongs to a different project');
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
	 * Edits a comment body. Only the author (== the owner) may edit. Stamps
	 * edited_at.
	 *
	 * @throws DoesNotExistException if the comment or project does not exist or is deleted
	 * @throws NotPermittedException if the actor is not the owner/author
	 * @throws InvalidInputException if the body is empty or too long
	 */
	public function editComment(int $commentId, string $body, string $actorUid): ProjectComment {
		$body = $this->normalizeBody($body);
		$comment = $this->loadComment($commentId);
		$this->loadOwnedProject($comment->getProjectId(), $actorUid);

		// The owner is the only reader/writer, so author == owner here, but keep
		// the author check explicit as defence in depth.
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
	 * Soft-deletes a comment. Only the author (== the owner) may delete. Deleting
	 * a top-level comment cascades to its non-deleted replies so the thread
	 * disappears as a unit.
	 *
	 * @throws DoesNotExistException if the comment or project does not exist or is deleted
	 * @throws NotPermittedException if the actor is not the owner/author
	 */
	public function deleteComment(int $commentId, string $actorUid): void {
		$comment = $this->loadComment($commentId);
		$this->loadOwnedProject($comment->getProjectId(), $actorUid);

		if ($comment->getAuthor() !== $actorUid) {
			throw new NotPermittedException('Only the author may delete this comment');
		}

		$now = time();

		// Cascade over a top-level comment's replies so none is orphaned. A
		// single set-based UPDATE also catches a reply inserted concurrently
		// with this delete (no read-then-write window).
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
			throw new InvalidInputException('Parent comment ' . $id . ' does not exist');
		}
		if ($parent->getDeletedAt() > 0) {
			throw new InvalidInputException('Parent comment ' . $id . ' does not exist');
		}
		return $parent;
	}

	/**
	 * The owner-only gate - mirrors {@see ProjectService::loadOwnedProject()}.
	 *
	 * @throws DoesNotExistException if the project does not exist
	 * @throws NotPermittedException if the actor does not own the project
	 */
	private function loadOwnedProject(int $id, string $uid): Project {
		$project = $this->projectMapper->find($id);
		if ($project->getOwner() !== $uid) {
			throw new NotPermittedException('Only the owner may act on this project');
		}
		return $project;
	}
}

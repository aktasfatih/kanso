<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Card comments / discussions — a ONE-level thread (top-level comments + their
 * replies, no deeper nesting). Reading needs READ on the card's board; posting
 * and replying need EDIT; a comment may be edited only by its author, and
 * deleted by its author or a board MANAGE-holder. Deleting a top-level comment
 * cascades a soft-delete over its replies so no reply is orphaned. Every
 * mutation appends a card-targeted row to the `kanso_changes` log (reusing
 * ENTITY_CARD/ACTION_UPDATE — a comment change is a card change for sync) so the
 * board ETag bumps and realtime clients refetch.
 *
 * Bodies are stored raw (markdown) and MUST be rendered through the client
 * DOMPurify sanitizer — never trusted as HTML.
 */
class CommentService {
	private const MAX_BODY_LENGTH = 10000;

	public function __construct(
		private CommentMapper $commentMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
	) {
	}

	/**
	 * The card's flat thread (non-deleted, oldest first). Requires READ.
	 *
	 * @return Comment[]
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function listForCard(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		return $this->commentMapper->findByCard($cardId);
	}

	/**
	 * Posts a comment ($parentCommentId null) or a reply to a top-level comment.
	 * Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the body is empty/too long, or the parent is invalid (missing, other card, deleted, or itself a reply — one level only)
	 */
	public function addComment(int $cardId, string $body, ?int $parentCommentId, string $actorUid): Comment {
		$body = $this->normalizeBody($body);
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		if ($parentCommentId !== null) {
			$parent = $this->loadParentComment($parentCommentId);
			if ($parent->getCardId() !== $cardId) {
				throw new InvalidInputException('Parent comment belongs to a different card');
			}
			if ($parent->getParentCommentId() !== null) {
				throw new InvalidInputException('Replies can only be one level deep');
			}
		}

		$comment = new Comment();
		$comment->setCardId($cardId);
		$comment->setParentCommentId($parentCommentId);
		$comment->setAuthor($actorUid);
		$comment->setBody($body);
		$comment->setCreatedAt(time());
		$comment->setEditedAt(0);
		$comment->setDeletedAt(0);
		$saved = $this->commentMapper->insert($comment);

		$this->notifyCard($card, $actorUid);

		return $saved;
	}

	/**
	 * Edits a comment body. Only the author may edit, and only with EDIT on the
	 * board. Stamps edited_at.
	 *
	 * @throws DoesNotExistException if the comment, card or board does not exist or is deleted
	 * @throws NotPermittedException if the actor is not the author or may not edit the board
	 * @throws InvalidInputException if the body is empty or too long
	 */
	public function editComment(int $commentId, string $body, string $actorUid): Comment {
		$body = $this->normalizeBody($body);
		$comment = $this->loadComment($commentId);
		$card = $this->loadCard($comment->getCardId());
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		if ($comment->getAuthor() !== $actorUid) {
			throw new NotPermittedException('Only the author may edit this comment');
		}

		if ($body === $comment->getBody()) {
			return $comment;
		}

		$comment->setBody($body);
		$comment->setEditedAt(time());
		$saved = $this->commentMapper->update($comment);

		$this->notifyCard($card, $actorUid);

		return $saved;
	}

	/**
	 * Soft-deletes a comment. The author (with EDIT) or a board MANAGE-holder
	 * may delete. Deleting a top-level comment cascades to its non-deleted
	 * replies so the thread disappears as a unit.
	 *
	 * @throws DoesNotExistException if the comment, card or board does not exist or is deleted
	 * @throws NotPermittedException if the actor may neither manage the board nor delete their own comment
	 */
	public function deleteComment(int $commentId, string $actorUid): void {
		$comment = $this->loadComment($commentId);
		$card = $this->loadCard($comment->getCardId());
		$board = $this->loadBoard($card->getBoardId());

		$isManager = ($this->permissionService->getPermissions($board, $actorUid) & PermissionService::PERMISSION_MANAGE) !== 0;
		$isAuthor = $comment->getAuthor() === $actorUid;
		if (!$isManager) {
			// A non-manager may only delete their own comment, and only with EDIT.
			$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
			if (!$isAuthor) {
				throw new NotPermittedException('Only the author or a board manager may delete this comment');
			}
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

		$this->notifyCard($card, $actorUid);
	}

	private function notifyCard(Card $card, string $actorUid): void {
		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$actorUid
		);
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
	private function loadComment(int $id): Comment {
		$comment = $this->commentMapper->find($id);
		if ($comment->getDeletedAt() > 0) {
			throw new DoesNotExistException('Comment ' . $id . ' is deleted');
		}
		return $comment;
	}

	/**
	 * @throws InvalidInputException if the parent comment is missing or deleted
	 */
	private function loadParentComment(int $id): Comment {
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
	 * @throws DoesNotExistException if the card does not exist or is deleted
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}

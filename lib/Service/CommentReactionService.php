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
use OCA\Kanso\Db\CommentReaction;
use OCA\Kanso\Db\CommentReactionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;

/**
 * Emoji reactions on card comments (#3550). A reaction reaches its board only
 * through comment -> card -> board (resolved + EDIT-checked here, mirroring
 * {@see CommentService}): READ lets you see reactions in the comment payload,
 * EDIT lets you toggle one. The emoji must be one of a FIXED allowed set -
 * this is deliberately NOT an arbitrary-emoji store.
 *
 * A react is idempotent (already-reacted is a no-op, guaranteed by the
 * (comment_id, uid, emoji) unique index) and so is an unreact (not-reacted is a
 * no-op). Every toggle that actually changes state appends a card-targeted row
 * to `kanso_changes` via {@see ChangeNotifier} so the board ETag bumps and
 * realtime clients refetch. Reactions live on COMMENTS only (v1), never cards.
 */
class CommentReactionService {
	/**
	 * The FIXED allowed emoji set. A reaction whose emoji is not one of these is
	 * rejected - the column is never an open free-text store.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_EMOJI = ['👍', '👎', '😄', '🎉', '❤️', '🚀', '👀'];

	public function __construct(
		private CommentReactionMapper $reactionMapper,
		private CommentMapper $commentMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private ChangeNotifier $changeNotifier,
		private PermissionService $permissionService,
		private CardVisibilityGuard $visibilityGuard,
	) {
	}

	/**
	 * Adds the actor's reaction to a comment. Requires EDIT. Idempotent: if the
	 * actor has already reacted with this emoji nothing changes and no change row
	 * is written.
	 *
	 * @throws DoesNotExistException if the comment, card or board is missing/deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the emoji is not in the allowed set
	 */
	public function react(int $commentId, string $emoji, string $actorUid): void {
		$emoji = $this->assertAllowedEmoji($emoji);
		[$comment, $card] = $this->resolveEditable($commentId, $actorUid);

		if ($this->reactionMapper->exists($commentId, $actorUid, $emoji)) {
			return;
		}

		$reaction = new CommentReaction();
		$reaction->setCommentId($commentId);
		$reaction->setUid($actorUid);
		$reaction->setEmoji($emoji);
		$reaction->setCreatedAt(time());
		try {
			$this->reactionMapper->insert($reaction);
		} catch (DbException $e) {
			// A concurrent identical react won the unique-index race; the desired
			// end state (the reaction exists) already holds - stay idempotent.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return;
			}
			throw $e;
		}

		$this->notifyCard($card, $actorUid);
	}

	/**
	 * Removes the actor's reaction from a comment. Requires EDIT. Idempotent: if
	 * the actor had not reacted with this emoji nothing changes and no change row
	 * is written.
	 *
	 * @throws DoesNotExistException if the comment, card or board is missing/deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the emoji is not in the allowed set
	 */
	public function unreact(int $commentId, string $emoji, string $actorUid): void {
		$emoji = $this->assertAllowedEmoji($emoji);
		[$comment, $card] = $this->resolveEditable($commentId, $actorUid);

		$removed = $this->reactionMapper->deleteReaction($commentId, $actorUid, $emoji);
		if ($removed > 0) {
			$this->notifyCard($card, $actorUid);
		}
	}

	/**
	 * Resolves comment -> card -> board and asserts EDIT on the board.
	 *
	 * @return array{0: Comment, 1: Card}
	 * @throws DoesNotExistException if the comment, card or board is missing/deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	private function resolveEditable(int $commentId, string $actorUid): array {
		$comment = $this->loadComment($commentId);
		$card = $this->loadCard($comment->getCardId());
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);
		return [$comment, $card];
	}

	private function notifyCard(Card $card, string $actorUid): void {
		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$card->getId(),
			Change::ACTION_UPDATE,
			$actorUid,
		);
	}

	/**
	 * @throws InvalidInputException if the emoji is not in the allowed set
	 */
	private function assertAllowedEmoji(string $emoji): string {
		if (!in_array($emoji, self::ALLOWED_EMOJI, true)) {
			throw new InvalidInputException('Unsupported reaction emoji');
		}
		return $emoji;
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

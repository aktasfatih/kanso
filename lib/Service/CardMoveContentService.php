<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardFileLink;
use OCA\Kanso\Db\CardFileLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\CommentReaction;
use OCA\Kanso\Db\CommentReactionMapper;

class CardMoveContentService {
	public function __construct(
		private CardMapper $cardMapper,
		private CommentMapper $commentMapper,
		private CommentReactionMapper $reactionMapper,
		private CardFileLinkMapper $fileLinkMapper,
		private CardAttachmentService $attachmentService,
	) {
	}

	/**
	 * @param callable(int, string): void|null $recordObject
	 * @return array<int, int>
	 */
	public function copyAttachments(Card $source, Card $target, Board $targetBoard, ?callable $recordObject = null): array {
		return $this->attachmentService->copyForMovedCard($source->getId(), $target->getId(), $targetBoard->getId(), $recordObject);
	}

	/**
	 * @param array<int, array<int, int>> $attachmentIdsByCard
	 * @param array<int, int> $cardIds
	 * @return array<int, int>
	 */
	public function copy(Card $source, Card $target, Board $targetBoard, array $attachmentIdsByCard = [], array $cardIds = []): array {
		$sourceId = $source->getId();
		$targetId = $target->getId();
		if (!array_key_exists($sourceId, $attachmentIdsByCard)) {
			$attachmentIdsByCard[$sourceId] = $this->copyAttachments($source, $target, $targetBoard);
			$cardIds[$sourceId] = $targetId;
		}
		$description = $source->getDescription();
		if ($description !== null) {
			$rewritten = $this->rewriteInlineUrls($description, $cardIds, $attachmentIdsByCard);
			if ($rewritten !== $description) {
				$target->setDescription($rewritten);
				$this->cardMapper->update($target);
			}
		}

		foreach ($this->fileLinkMapper->findAllByCardForMove($sourceId) as $link) {
			$copy = new CardFileLink();
			$copy->setCardId($targetId);
			$copy->setFileId($link->getFileId());
			$copy->setName($link->getName());
			$copy->setMime($link->getMime());
			$copy->setLinkedBy($link->getLinkedBy());
			$copy->setCreatedAt($link->getCreatedAt());
			$this->fileLinkMapper->insert($copy);
		}

		$comments = $this->commentMapper->findAllByCardForMove($sourceId);
		$commentIds = [];
		// Process roots first even if imported timestamps order a reply before its parent.
		foreach ([false, true] as $replies) {
			foreach ($comments as $comment) {
				$parentId = $comment->getParentCommentId();
				if (($parentId !== null) !== $replies) {
					continue;
				}
				if ($parentId !== null && !isset($commentIds[$parentId])) {
					throw new \RuntimeException('Cannot copy a comment with a missing thread');
				}
				$copy = new Comment();
				$copy->setCardId($targetId);
				$copy->setParentCommentId($parentId === null ? null : $commentIds[$parentId]);
				$copy->setAuthor($comment->getAuthor());
				if ($targetBoard->getAllowAiActors() !== true && $comment->getActorName() !== null) {
					throw new InvalidInputException('The destination board does not allow AI-attributed comments');
				}
				if ($targetBoard->getAllowAiActors() === true) {
					$copy->setActorName($comment->getActorName());
				}
				$copy->setBody($this->rewriteInlineUrls($comment->getBody(), $cardIds, $attachmentIdsByCard));
				$copy->setCreatedAt($comment->getCreatedAt());
				$copy->setEditedAt($comment->getEditedAt());
				$copy->setDeletedAt($comment->getDeletedAt());
				$copy->setResolvedAt($comment->getResolvedAt());
				$copy = $this->commentMapper->insert($copy);
				$commentIds[$comment->getId()] = $copy->getId();
			}
		}

		foreach ($this->reactionMapper->findEntitiesByComments(array_keys($commentIds)) as $reaction) {
			$copy = new CommentReaction();
			$copy->setCommentId($commentIds[$reaction->getCommentId()]);
			$copy->setUid($reaction->getUid());
			$copy->setEmoji($reaction->getEmoji());
			$copy->setCreatedAt($reaction->getCreatedAt());
			$this->reactionMapper->insert($copy);
		}
		return $commentIds;
	}

	/**
	 * @param array<int, int> $cardIds
	 * @param array<int, array<int, int>> $attachmentIdsByCard
	 */
	private function rewriteInlineUrls(string $text, array $cardIds, array $attachmentIdsByCard): string {
		if ($attachmentIdsByCard === []) {
			return $text;
		}
		$pattern = '~(?<![\w:/@.\-])(?P<prefix>/(?:[^/\\\\\s()<>"\'@:?#]+/)*apps/kanso/api)/cards/(?P<card>\d+)/attachments/(?P<attachment>\d+)/inline(?![\w/?#.-])~';
		return preg_replace_callback($pattern, static function (array $match) use ($cardIds, $attachmentIdsByCard): string {
			$sourceId = (int)$match['card'];
			$attachmentId = (int)$match['attachment'];
			if (!isset($cardIds[$sourceId], $attachmentIdsByCard[$sourceId][$attachmentId])) {
				return $match[0];
			}
			return $match['prefix'] . '/cards/' . $cardIds[$sourceId] . '/attachments/' . $attachmentIdsByCard[$sourceId][$attachmentId] . '/inline';
		}, $text) ?? $text;
	}
}

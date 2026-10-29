<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardFileLink;
use OCA\Kanso\Db\CardFileLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\CommentReaction;
use OCA\Kanso\Db\CommentReactionMapper;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\CardMoveContentService;
use PHPUnit\Framework\TestCase;

class CardMoveContentServiceTest extends TestCase {
	public function testCopiesDiscussionLinksAndRewritesOnlyOwnInlineUrls(): void {
		$cards = $this->createMock(CardMapper::class);
		$comments = $this->createMock(CommentMapper::class);
		$reactions = $this->createMock(CommentReactionMapper::class);
		$links = $this->createMock(CardFileLinkMapper::class);
		$attachments = $this->createMock(CardAttachmentService::class);
		$service = new CardMoveContentService($cards, $comments, $reactions, $links, $attachments);

		$source = new Card();
		$source->setId(9);
		$source->setDescription('![a](/apps/kanso/api/cards/9/attachments/5/inline) ![b](https://evil.example/apps/kanso/api/cards/9/attachments/5/inline) ![c](/apps/kanso/api/cards/9/attachments/6/inline)');
		$target = new Card();
		$target->setId(42);
		$board = new Board();
		$board->setId(2);
		$board->setAllowAiActors(false);
		$attachments->expects(self::once())->method('copyForMovedCard')->with(9, 42, 2)->willReturn([5 => 77]);
		$cards->expects(self::once())->method('update')->with($target)->willReturn($target);

		$link = new CardFileLink();
		$link->setCardId(9);
		$link->setFileId(123);
		$link->setName('doc.txt');
		$link->setMime('text/plain');
		$link->setLinkedBy('alice');
		$link->setCreatedAt(10);
		$links->method('findByCard')->with(9)->willReturn([$link]);
		$links->expects(self::once())->method('insert')->willReturnCallback(function (CardFileLink $copy): CardFileLink {
			self::assertSame(42, $copy->getCardId());
			self::assertSame(123, $copy->getFileId());
			self::assertSame('alice', $copy->getLinkedBy());
			return $copy;
		});

		$root = $this->comment(1, null, 'root ![](/apps/kanso/api/cards/9/attachments/5/inline)');
		$reply = $this->comment(2, 1, 'reply');
		$comments->method('findByCard')->with(9)->willReturn([$reply, $root]);
		$copied = [];
		$comments->expects(self::exactly(2))->method('insert')->willReturnCallback(function (Comment $copy) use (&$copied): Comment {
			$copy->setId(count($copied) + 101);
			$copied[] = $copy;
			return $copy;
		});
		$reaction = new CommentReaction();
		$reaction->setCommentId(2);
		$reaction->setUid('bob');
		$reaction->setEmoji('👍');
		$reaction->setCreatedAt(123);
		$reactions->method('findEntitiesByComments')->with([1, 2])->willReturn([$reaction]);
		$reactions->expects(self::once())->method('insert')->willReturnCallback(function (CommentReaction $copy): CommentReaction {
			self::assertSame(102, $copy->getCommentId());
			self::assertSame(123, $copy->getCreatedAt());
			self::assertSame('bob', $copy->getUid());
			return $copy;
		});

		$service->copy($source, $target, $board);
		self::assertSame('![a](/apps/kanso/api/cards/42/attachments/77/inline) ![b](https://evil.example/apps/kanso/api/cards/9/attachments/5/inline) ![c](/apps/kanso/api/cards/9/attachments/6/inline)', $target->getDescription());
		self::assertSame('root ![](/apps/kanso/api/cards/42/attachments/77/inline)', $copied[0]->getBody());
		self::assertNull($copied[0]->getActorName());
		self::assertSame(101, $copied[1]->getParentCommentId());
		self::assertSame(42, $copied[1]->getCardId());
		self::assertSame(11, $copied[0]->getResolvedAt());
	}

	private function comment(int $id, ?int $parent, string $body): Comment {
		$comment = new Comment();
		$comment->setId($id);
		$comment->setCardId(9);
		$comment->setParentCommentId($parent);
		$comment->setAuthor('alice');
		$comment->setActorName('AI agent');
		$comment->setBody($body);
		$comment->setCreatedAt(2);
		$comment->setEditedAt(3);
		$comment->setDeletedAt(0);
		$comment->setResolvedAt(11);
		return $comment;
	}
}

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
	public function testRewritesAttachmentsFromOtherMovedCards(): void {
		$cards = $this->createMock(CardMapper::class);
		$comments = $this->createMock(CommentMapper::class);
		$reactions = $this->createMock(CommentReactionMapper::class);
		$links = $this->createMock(CardFileLinkMapper::class);
		$attachments = $this->createMock(CardAttachmentService::class);
		$attachments->expects(self::never())->method('copyForMovedCard');
		$service = new CardMoveContentService($cards, $comments, $reactions, $links, $attachments);
		$source = new Card();
		$source->setId(9);
		$source->setDescription('![other](/apps/kanso/api/cards/10/attachments/8/inline)');
		$target = new Card();
		$target->setId(42);
		$board = new Board();
		$board->setId(2);
		$cards->expects(self::once())->method('update')->with($target)->willReturn($target);
		$service->copy($source, $target, $board, [9 => [], 10 => [8 => 88]], [9 => 42, 10 => 43]);
		self::assertSame('![other](/apps/kanso/api/cards/43/attachments/88/inline)', $target->getDescription());
	}

	public function testCopiesLiveReplyAndDeletedRootTogether(): void {
		$comments = $this->createMock(CommentMapper::class);
		$root = $this->comment(1, null, 'deleted root');
		$root->setDeletedAt(100);
		$reply = $this->comment(2, 1, 'live reply');
		$comments->method('findAllByCardForMove')->with(9)->willReturn([$root, $reply]);
		$copies = [];
		$comments->expects(self::exactly(2))->method('insert')->willReturnCallback(function (Comment $comment) use (&$copies): Comment {
			$comment->setId(101 + count($copies));
			$copies[] = $comment;
			return $comment;
		});
		$service = new CardMoveContentService(
			$this->createMock(CardMapper::class),
			$comments,
			$this->createMock(CommentReactionMapper::class),
			$this->createMock(CardFileLinkMapper::class),
			$this->createMock(CardAttachmentService::class),
		);
		$source = new Card();
		$source->setId(9);
		$target = new Card();
		$target->setId(42);
		$board = new Board();
		$board->setAllowAiActors(true);
		self::assertSame([1 => 101, 2 => 102], $service->copy($source, $target, $board, [9 => []], [9 => 42]));
		self::assertSame(100, $copies[0]->getDeletedAt());
		self::assertSame(101, $copies[1]->getParentCommentId());
		self::assertSame(0, $copies[1]->getDeletedAt());
	}

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
		$board->setAllowAiActors(true);
		$attachments->expects(self::once())->method('copyForMovedCard')->with(9, 42, 2)->willReturn([5 => 77]);
		$cards->expects(self::once())->method('update')->with($target)->willReturn($target);

		$link = new CardFileLink();
		$link->setCardId(9);
		$link->setFileId(123);
		$link->setName('doc.txt');
		$link->setMime('text/plain');
		$link->setLinkedBy('alice');
		$link->setCreatedAt(10);
		$links->method('findAllByCardForMove')->with(9)->willReturn([$link]);
		$links->expects(self::once())->method('insert')->willReturnCallback(function (CardFileLink $copy): CardFileLink {
			self::assertSame(42, $copy->getCardId());
			self::assertSame(123, $copy->getFileId());
			self::assertSame('alice', $copy->getLinkedBy());
			self::assertSame('doc.txt', $copy->getName());
			self::assertSame('text/plain', $copy->getMime());
			return $copy;
		});

		$root = $this->comment(1, null, 'root ![](/apps/kanso/api/cards/9/attachments/5/inline)');
		$reply = $this->comment(2, 1, 'reply');
		$comments->method('findAllByCardForMove')->with(9)->willReturn([$reply, $root]);
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
		self::assertSame('AI agent', $copied[0]->getActorName());
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

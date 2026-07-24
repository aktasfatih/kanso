<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\CommentService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CommentServiceTest extends TestCase {
	private CommentMapper&MockObject $commentMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private SubscriptionService&MockObject $subscriptionService;
	private CommentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->subscriptionService = $this->createMock(SubscriptionService::class);
		$this->service = new CommentService(
			$this->commentMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->subscriptionService,
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId(5);
		$card->setTitle('Existing card');
		$card->setSortKey('I');
		$card->setDeletedAt(0);
		return $card;
	}

	private function comment(int $id, int $cardId = 9, ?int $parentId = null, string $author = 'bob', string $body = 'hello'): Comment {
		$comment = new Comment();
		$comment->setId($id);
		$comment->setCardId($cardId);
		$comment->setParentCommentId($parentId);
		$comment->setAuthor($author);
		$comment->setBody($body);
		$comment->setCreatedAt(1000);
		$comment->setEditedAt(0);
		$comment->setDeletedAt(0);
		return $comment;
	}

	private function expectCardLoaded(): Board {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	// ---- listForCard ------------------------------------------------------

	public function testListForCardAssertsReadAndReturnsThread(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'reader', PermissionService::PERMISSION_READ);
		$thread = [$this->comment(1), $this->comment(2)];
		$this->commentMapper->method('findByCard')->with(9)->willReturn($thread);

		self::assertSame($thread, $this->service->listForCard(9, 'reader'));
	}

	// ---- addComment -------------------------------------------------------

	public function testAddTopLevelCommentInsertsAndWritesCardChangeRow(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT);
		$this->commentMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (Comment $c): Comment {
				self::assertSame('Hello world', $c->getBody());
				self::assertNull($c->getParentCommentId());
				self::assertSame('bob', $c->getAuthor());
				self::assertSame(9, $c->getCardId());
				return $c;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob')
			->willReturn(new Change());
		$this->subscriptionService->expects(self::once())
			->method('handleNewComment')
			->with(9, null, 'bob');

		$this->service->addComment(9, '  Hello world  ', null, 'bob');
	}

	public function testAddReplyToTopLevelCommentSucceeds(): void {
		$this->expectCardLoaded();
		$this->commentMapper->method('find')->with(50)->willReturn($this->comment(50, 9, null));
		$this->commentMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (Comment $c): Comment {
				self::assertSame(50, $c->getParentCommentId());
				return $c;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->addComment(9, 'a reply', 50, 'bob');
	}

	public function testAddCommentRejectsEmptyBody(): void {
		$this->expectCardLoaded();
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->addComment(9, '   ', null, 'bob');
	}

	public function testAddCommentRejectsOverlongBody(): void {
		$this->expectCardLoaded();
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->addComment(9, str_repeat('x', 10001), null, 'bob');
	}

	public function testAddReplyToAReplyIsRejected(): void {
		$this->expectCardLoaded();
		// The chosen parent is itself a reply (has a parent) — one level only.
		$this->commentMapper->method('find')->with(50)->willReturn($this->comment(50, 9, 40));
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('one level');
		$this->service->addComment(9, 'nested', 50, 'bob');
	}

	public function testAddReplyToCommentOnAnotherCardIsRejected(): void {
		$this->expectCardLoaded();
		$this->commentMapper->method('find')->with(50)->willReturn($this->comment(50, 77, null));
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('different card');
		$this->service->addComment(9, 'reply', 50, 'bob');
	}

	public function testAddReplyToMissingParentIsRejected(): void {
		$this->expectCardLoaded();
		$this->commentMapper->method('find')->with(50)->willThrowException(new DoesNotExistException('gone'));
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->addComment(9, 'reply', 50, 'bob');
	}

	public function testAddCommentAssertsActorEditPermission(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->addComment(9, 'nope', null, 'mallory');
	}

	public function testAddCommentRejectsDeletedCard(): void {
		$card = $this->card();
		$card->setDeletedAt(1234);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->addComment(9, 'hi', null, 'bob');
	}

	// ---- editComment ------------------------------------------------------

	public function testEditCommentByAuthorUpdatesBodyAndStampsEditedAt(): void {
		$comment = $this->comment(50, 9, null, 'bob', 'old');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->commentMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Comment $c): Comment {
				self::assertSame('new body', $c->getBody());
				self::assertGreaterThan(0, $c->getEditedAt());
				return $c;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$this->service->editComment(50, 'new body', 'bob');
	}

	public function testEditCommentRejectsNonAuthor(): void {
		$comment = $this->comment(50, 9, null, 'bob', 'old');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->commentMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->editComment(50, 'hijack', 'carol');
	}

	public function testEditCommentNoOpWritesNoChangeRow(): void {
		$comment = $this->comment(50, 9, null, 'bob', 'same');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->commentMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->editComment(50, 'same', 'bob');
	}

	// ---- deleteComment ----------------------------------------------------

	public function testDeleteTopLevelCommentByAuthorCascadesToReplies(): void {
		$comment = $this->comment(50, 9, null, 'bob');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// Author holds EDIT but not MANAGE.
		$this->permissionService->method('getPermissions')
			->willReturn(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);

		// The replies are cascaded in one set-based UPDATE...
		$this->commentMapper->expects(self::once())
			->method('softDeleteRepliesOf')
			->with(50, self::greaterThan(0));
		// ...and the top-level comment itself is soft-deleted.
		$this->commentMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Comment $c): Comment {
				self::assertSame(50, $c->getId());
				self::assertGreaterThan(0, $c->getDeletedAt());
				return $c;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$this->service->deleteComment(50, 'bob');
	}

	public function testManagerCanDeleteAnotherUsersComment(): void {
		$comment = $this->comment(50, 9, null, 'bob');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('getPermissions')
			->willReturn(PermissionService::PERMISSION_ALL);
		$this->commentMapper->method('findReplies')->with(50)->willReturn([]);
		$this->commentMapper->expects(self::once())->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		// 'admin' is not the author but holds MANAGE.
		$this->service->deleteComment(50, 'admin');
	}

	public function testDeleteCommentRejectsNonAuthorNonManager(): void {
		$comment = $this->comment(50, 9, null, 'bob');
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// EDIT but not MANAGE, and not the author.
		$this->permissionService->method('getPermissions')
			->willReturn(PermissionService::PERMISSION_READ | PermissionService::PERMISSION_EDIT);
		$this->commentMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->deleteComment(50, 'carol');
	}
}

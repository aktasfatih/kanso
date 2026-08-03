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
use OCA\Kanso\Db\CommentReaction;
use OCA\Kanso\Db\CommentReactionMapper;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\CommentReactionService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CommentReactionServiceTest extends TestCase {
	private CommentReactionMapper&MockObject $reactionMapper;
	private CommentMapper&MockObject $commentMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private CommentReactionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->reactionMapper = $this->createMock(CommentReactionMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->service = new CommentReactionService(
			$this->reactionMapper,
			$this->commentMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
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

	private function comment(int $id = 50, int $cardId = 9): Comment {
		$comment = new Comment();
		$comment->setId($id);
		$comment->setCardId($cardId);
		$comment->setParentCommentId(null);
		$comment->setAuthor('bob');
		$comment->setBody('hi');
		$comment->setCreatedAt(1000);
		$comment->setEditedAt(0);
		$comment->setDeletedAt(0);
		return $comment;
	}

	/** Wires comment 50 -> card 9 -> board 1 all live. */
	private function wireChain(): Board {
		$this->commentMapper->method('find')->with(50)->willReturn($this->comment());
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	// ---- react ------------------------------------------------------------

	public function testReactInsertsAndWritesCardChangeRow(): void {
		$board = $this->wireChain();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT);
		$this->reactionMapper->method('exists')->with(50, 'bob', '👍')->willReturn(false);
		$this->reactionMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (CommentReaction $r): CommentReaction {
				self::assertSame(50, $r->getCommentId());
				self::assertSame('bob', $r->getUid());
				self::assertSame('👍', $r->getEmoji());
				return $r;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob')
			->willReturn(new Change());

		$this->service->react(50, '👍', 'bob');
	}

	public function testReactIsIdempotentWhenAlreadyReacted(): void {
		$this->wireChain();
		$this->permissionService->method('assertPermission');
		// Already reacted → no insert, no change row (unique index guarantee).
		$this->reactionMapper->method('exists')->with(50, 'bob', '👍')->willReturn(true);
		$this->reactionMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->react(50, '👍', 'bob');
	}

	public function testReactSwallowsUniqueConstraintRace(): void {
		$this->wireChain();
		$this->permissionService->method('assertPermission');
		// exists() said no, but a concurrent react won the unique-index race.
		$this->reactionMapper->method('exists')->willReturn(false);
		$dbException = $this->createMock(DbException::class);
		$dbException->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->reactionMapper->method('insert')->willThrowException($dbException);
		// The end state already holds, so it stays a no-op (no throw).
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->react(50, '👍', 'bob');
	}

	public function testReactRejectsInvalidEmoji(): void {
		// Emoji is validated BEFORE any DB work.
		$this->reactionMapper->expects(self::never())->method('insert');
		$this->permissionService->expects(self::never())->method('assertPermission');

		$this->expectException(InvalidInputException::class);
		$this->service->react(50, '💩', 'bob');
	}

	public function testReactRequiresEditPermission(): void {
		$board = $this->wireChain();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->reactionMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->react(50, '👍', 'mallory');
	}

	public function testReactRejectsDeletedComment(): void {
		$comment = $this->comment();
		$comment->setDeletedAt(1234);
		$this->commentMapper->method('find')->with(50)->willReturn($comment);
		$this->reactionMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->react(50, '👍', 'bob');
	}

	// ---- unreact ----------------------------------------------------------

	public function testUnreactRemovesAndWritesChangeRow(): void {
		$this->wireChain();
		$this->permissionService->method('assertPermission');
		$this->reactionMapper->expects(self::once())
			->method('deleteReaction')->with(50, 'bob', '👍')->willReturn(1);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$this->service->unreact(50, '👍', 'bob');
	}

	public function testUnreactIsIdempotentWhenNothingRemoved(): void {
		$this->wireChain();
		$this->permissionService->method('assertPermission');
		// Not reacted → 0 rows removed → no change row.
		$this->reactionMapper->method('deleteReaction')->willReturn(0);
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->unreact(50, '👍', 'bob');
	}

	public function testUnreactRequiresEditPermission(): void {
		$board = $this->wireChain();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->reactionMapper->expects(self::never())->method('deleteReaction');

		$this->expectException(NotPermittedException::class);
		$this->service->unreact(50, '👍', 'mallory');
	}

	public function testUnreactRejectsInvalidEmoji(): void {
		$this->reactionMapper->expects(self::never())->method('deleteReaction');

		$this->expectException(InvalidInputException::class);
		$this->service->unreact(50, 'not-an-emoji', 'bob');
	}
}

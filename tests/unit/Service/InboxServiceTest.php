<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\SubscriptionMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\InboxService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InboxServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private SubscriptionMapper&MockObject $subscriptionMapper;
	private CommentMapper&MockObject $commentMapper;
	private IUserManager&MockObject $userManager;
	private InboxService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->subscriptionMapper = $this->createMock(SubscriptionMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new InboxService(
			$this->boardService,
			$this->subscriptionMapper,
			$this->commentMapper,
			$this->userManager
		);
	}

	private function board(int $id): Board {
		$b = new Board();
		$b->setId($id);
		$b->setDeletedAt(0);
		return $b;
	}

	public function testFindMineReturnsCommentsOnFollowedCardsInReadableBoards(): void {
		$this->boardService->method('findAll')->with('bob')->willReturn([$this->board(1), $this->board(2)]);
		$this->subscriptionMapper->method('findSubscribedCardIds')->with('bob')->willReturn([9, 10]);
		$rows = [[
			'id' => 5, 'cardId' => 9, 'boardId' => 1, 'cardTitle' => 'Card', 'boardTitle' => 'Board',
			'author' => 'alice', 'body' => 'hi', 'createdAt' => 100,
		]];
		$this->commentMapper->expects(self::once())
			->method('findInboxForCards')
			->with([9, 10], [1, 2], 'bob', 50)
			->willReturn($rows);
		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice Doe');
		$this->userManager->method('get')->with('alice')->willReturn($alice);

		$result = $this->service->findMine('bob');
		self::assertCount(1, $result);
		self::assertSame('Alice Doe', $result[0]['authorDisplayName']);
		self::assertSame('alice', $result[0]['author']);
	}

	public function testFindMineEmptyWhenNoReadableBoards(): void {
		$this->boardService->method('findAll')->with('bob')->willReturn([]);
		$this->subscriptionMapper->expects(self::never())->method('findSubscribedCardIds');
		$this->commentMapper->expects(self::never())->method('findInboxForCards');

		self::assertSame([], $this->service->findMine('bob'));
	}

	public function testFindMineEmptyWhenNoSubscribedCards(): void {
		$this->boardService->method('findAll')->with('bob')->willReturn([$this->board(1)]);
		$this->subscriptionMapper->method('findSubscribedCardIds')->with('bob')->willReturn([]);
		$this->commentMapper->expects(self::never())->method('findInboxForCards');

		self::assertSame([], $this->service->findMine('bob'));
	}
}

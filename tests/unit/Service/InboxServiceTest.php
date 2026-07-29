<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
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
	private ChangeMapper&MockObject $changeMapper;
	private IUserManager&MockObject $userManager;
	private InboxService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->subscriptionMapper = $this->createMock(SubscriptionMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new InboxService(
			$this->boardService,
			$this->subscriptionMapper,
			$this->commentMapper,
			$this->changeMapper,
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

	public function testFindMineMergesCommentsAndStatusChangesNewestFirst(): void {
		$this->boardService->method('findAll')->with('bob')->willReturn([$this->board(1)]);
		$this->subscriptionMapper->method('findSubscribedCardIds')->with('bob')->willReturn([9]);
		$this->commentMapper->method('findInboxForCards')->willReturn([[
			'id' => 5, 'cardId' => 9, 'boardId' => 1, 'cardTitle' => 'C', 'boardTitle' => 'B',
			'author' => 'alice', 'body' => 'hi', 'createdAt' => 100,
		]]);
		// Only the two surfaced verbs are requested; the change is newer.
		$this->changeMapper->expects(self::once())
			->method('findInboxForCards')
			->with([9], [1], 'bob', [Change::VERB_ASSIGNED, Change::VERB_REVIEW_REQUESTED], 50)
			->willReturn([[
				'id' => 7, 'cardId' => 9, 'boardId' => 1, 'cardTitle' => 'C', 'boardTitle' => 'B',
				'author' => 'carol', 'verb' => Change::VERB_REVIEW_REQUESTED, 'createdAt' => 200,
			]]);
		$this->userManager->method('get')->willReturn(null); // display name falls back to uid

		$result = $this->service->findMine('bob');

		self::assertCount(2, $result);
		// Newest first: the review-request change (ts 200) precedes the comment (ts 100).
		self::assertSame('change', $result[0]['type']);
		self::assertSame(Change::VERB_REVIEW_REQUESTED, $result[0]['verb']);
		self::assertSame('carol', $result[0]['authorDisplayName']);
		self::assertSame('comment', $result[1]['type']);
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

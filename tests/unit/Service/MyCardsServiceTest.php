<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\MyCardsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MyCardsServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private CardMapper&MockObject $cardMapper;
	private BoardAccess&MockObject $boardAccess;
	private MyCardsService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->service = new MyCardsService($this->boardService, $this->cardMapper, $this->boardAccess);
	}

	private function board(int $id): Board {
		$board = new Board();
		$board->setId($id);
		return $board;
	}

	public function testFindMineRestrictsToReadableBoardsAndTheUser(): void {
		// The ACL boundary: only the boards findAll() returns (the readable set)
		// are ever passed to the query, and only the current user's identity.
		$this->boardService->expects($this->once())
			->method('findAll')
			->with('alice')
			->willReturn([$this->board(3), $this->board(9)]);

		$roles = [3 => ViewerContext::ROLE_INTERNAL, 9 => ViewerContext::ROLE_EXTERNAL];
		$this->boardAccess->expects($this->once())
			->method('rolesFor')
			->willReturn($roles);

		$expected = [['id' => 1, 'boardId' => 3, 'title' => 'A task']];
		$this->cardMapper->expects($this->once())
			->method('findAssignedInBoards')
			->with(['alice'], [3, 9], 'alice', $roles, MyCardsService::LIMIT + 1)
			->willReturn($expected);

		$feed = $this->service->findMine('alice');
		$this->assertSame($expected, $feed['cards']);
		$this->assertFalse($feed['truncated']);
	}

	public function testFindMineWithNoReadableBoardsQueriesEmptySet(): void {
		$this->boardService->method('findAll')->willReturn([]);
		$this->boardAccess->method('rolesFor')->willReturn([]);
		$this->cardMapper->expects($this->once())
			->method('findAssignedInBoards')
			->with(['bob'], [], 'bob', [], MyCardsService::LIMIT + 1)
			->willReturn([]);

		$feed = $this->service->findMine('bob');
		$this->assertSame([], $feed['cards']);
		$this->assertFalse($feed['truncated']);
	}

	/**
	 * @return list<array<string, mixed>> $count synthetic assigned-card rows
	 */
	private function rows(int $count): array {
		$rows = [];
		for ($i = 1; $i <= $count; $i++) {
			$rows[] = ['id' => $i, 'boardId' => 3, 'title' => 'Task ' . $i];
		}
		return $rows;
	}

	public function testFindMineSignalsTheCapWhenMoreCardsAreAssignedThanTheLimit(): void {
		// 201 assigned cards: the user is over the cap. The feed must SAY it is
		// truncated - a silent 200-row slice makes the page (and the nav badge
		// counting the same array) claim a wrong, frozen total.
		$this->boardService->method('findAll')->willReturn([$this->board(3)]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);
		$this->cardMapper->expects($this->once())
			->method('findAssignedInBoards')
			// LIMIT + 1 is the probe that makes truncation detectable at all.
			->with(['alice'], [3], 'alice', [3 => ViewerContext::ROLE_INTERNAL], MyCardsService::LIMIT + 1)
			->willReturn($this->rows(MyCardsService::LIMIT + 1));

		$feed = $this->service->findMine('alice');

		$this->assertTrue($feed['truncated'], 'a feed over the cap must report itself truncated');
		$this->assertSame(MyCardsService::LIMIT, $feed['limit']);
		// The probe row is never handed to the client.
		$this->assertCount(MyCardsService::LIMIT, $feed['cards']);
		$this->assertSame(1, $feed['cards'][0]['id']);
		$this->assertSame(MyCardsService::LIMIT, $feed['cards'][MyCardsService::LIMIT - 1]['id']);
	}

	public function testFindMineAtExactlyTheLimitIsNotTruncated(): void {
		// Exactly LIMIT rows means the query found no row beyond the cap - the
		// feed is complete and must not cry "more".
		$this->boardService->method('findAll')->willReturn([$this->board(3)]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);
		$this->cardMapper->method('findAssignedInBoards')->willReturn($this->rows(MyCardsService::LIMIT));

		$feed = $this->service->findMine('alice');

		$this->assertFalse($feed['truncated']);
		$this->assertCount(MyCardsService::LIMIT, $feed['cards']);
	}
}

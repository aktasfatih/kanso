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
			->with(['alice'], [3, 9], 'alice', $roles)
			->willReturn($expected);

		$this->assertSame($expected, $this->service->findMine('alice'));
	}

	public function testFindMineWithNoReadableBoardsQueriesEmptySet(): void {
		$this->boardService->method('findAll')->willReturn([]);
		$this->boardAccess->method('rolesFor')->willReturn([]);
		$this->cardMapper->expects($this->once())
			->method('findAssignedInBoards')
			->with(['bob'], [], 'bob', [])
			->willReturn([]);

		$this->assertSame([], $this->service->findMine('bob'));
	}
}

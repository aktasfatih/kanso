<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\MyStepsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MyStepsServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private BoardAccess&MockObject $boardAccess;
	private MyStepsService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->service = new MyStepsService($this->boardService, $this->checklistItemMapper, $this->boardAccess);
	}

	private function board(int $id): Board {
		$board = new Board();
		$board->setId($id);
		return $board;
	}

	public function testFindMineRestrictsToReadableBoardsAndAppliesTheRoleMap(): void {
		// The ACL boundary (mirrors MyCardsService): only the boards findAll()
		// returns are ever queried, and the viewer's per-board role map rides
		// along so the card-visibility scope can hide steps of hidden cards.
		$this->boardService->expects($this->once())
			->method('findAll')
			->with('alice')
			->willReturn([$this->board(3), $this->board(9)]);

		$roles = [3 => ViewerContext::ROLE_INTERNAL, 9 => ViewerContext::ROLE_EXTERNAL];
		$this->boardAccess->expects($this->once())
			->method('rolesFor')
			->willReturn($roles);

		$expected = [['id' => 7, 'cardId' => 1, 'boardId' => 3, 'title' => 'Send the contract']];
		$this->checklistItemMapper->expects($this->once())
			->method('findOpenAssignedInBoards')
			->with('alice', [3, 9], $roles)
			->willReturn($expected);

		$this->assertSame($expected, $this->service->findMine('alice'));
	}

	public function testFindMineWithNoReadableBoardsQueriesEmptySet(): void {
		$this->boardService->method('findAll')->willReturn([]);
		$this->boardAccess->method('rolesFor')->willReturn([]);
		$this->checklistItemMapper->expects($this->once())
			->method('findOpenAssignedInBoards')
			->with('bob', [], [])
			->willReturn([]);

		$this->assertSame([], $this->service->findMine('bob'));
	}
}

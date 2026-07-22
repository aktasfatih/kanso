<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SortKeyService;
use OCA\Kanso\Service\StackService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StackServiceTest extends TestCase {
	private StackMapper&MockObject $stackMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeMapper&MockObject $changeMapper;
	private PermissionService&MockObject $permissionService;
	private StackService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->service = new StackService(
			$this->stackMapper,
			$this->boardMapper,
			$this->changeMapper,
			$this->permissionService,
			new SortKeyService()
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function stack(int $id = 5, int $boardId = 1, string $sortKey = 'I'): Stack {
		$stack = new Stack();
		$stack->setId($id);
		$stack->setBoardId($boardId);
		$stack->setTitle('Existing stack');
		$stack->setSortKey($sortKey);
		$stack->setDeletedAt(0);
		return $stack;
	}

	public function testCreateOnEmptyBoardUsesInitialSortKey(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([]);
		$this->stackMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Stack $stack): Stack {
				self::assertSame('I', $stack->getSortKey());
				self::assertSame('To do', $stack->getTitle());
				self::assertSame(1, $stack->getBoardId());
				$stack->setId(7);
				return $stack;
			});
		$this->changeMapper->expects(self::once())
			->method('insertChange')
			->with(
				1,
				Change::ENTITY_STACK,
				7,
				Change::ACTION_CREATE,
				'alice',
				self::greaterThan(0)
			)
			->willReturn(new Change());

		$stack = $this->service->create(1, 'To do', 'alice');
		self::assertSame(7, $stack->getId());
	}

	public function testCreateAppendsAfterLastStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(5, 1, 'I'),
			$this->stack(6, 1, 'J'),
		]);
		$this->stackMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Stack $stack): Stack {
				// after('J') === 'K'
				self::assertSame('K', $stack->getSortKey());
				$stack->setId(8);
				return $stack;
			});
		$this->changeMapper->expects(self::once())
			->method('insertChange')
			->willReturn(new Change());

		$this->service->create(1, 'Done', 'alice');
	}

	public function testCreateAssertsEditPermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->stackMapper->expects(self::never())->method('insert');
		$this->changeMapper->expects(self::never())->method('insertChange');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, 'To do', 'bob');
	}

	public function testUpdateAppliesFieldsAndWritesChangeRow(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeMapper->expects(self::once())
			->method('insertChange')
			->with(
				1,
				Change::ENTITY_STACK,
				5,
				Change::ACTION_UPDATE,
				'alice',
				self::greaterThan(0)
			)
			->willReturn(new Change());

		$updated = $this->service->update(5, 'Renamed', true, 'alice');
		self::assertSame('Renamed', $updated->getTitle());
		self::assertTrue($updated->getArchived());
	}

	public function testDeleteSoftDeletesAndWritesChangeRow(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->expects(self::once())
			->method('update')
			->with(self::callback(static fn (Stack $s): bool => $s->getDeletedAt() > 0))
			->willReturnArgument(0);
		$this->changeMapper->expects(self::once())
			->method('insertChange')
			->with(
				1,
				Change::ENTITY_STACK,
				5,
				Change::ACTION_DELETE,
				'alice',
				self::greaterThan(0)
			)
			->willReturn(new Change());

		$deleted = $this->service->delete(5, 'alice');
		self::assertGreaterThan(0, $deleted->getDeletedAt());
	}
}

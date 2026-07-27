<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardServiceTest extends TestCase {
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private BoardService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->service = new BoardService(
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService
		);
	}

	private function board(int $id = 1, string $owner = 'alice'): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner($owner);
		$board->setTitle('Existing board');
		$board->setDeletedAt(0);
		return $board;
	}

	public function testCreateInsertsBoardAndChangeRow(): void {
		$this->boardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Board $board): Board {
				self::assertSame('My board', $board->getTitle());
				self::assertSame('alice', $board->getOwner());
				self::assertSame('AABBCC', $board->getColor());
				self::assertFalse($board->getArchived());
				self::assertSame(0, $board->getDeletedAt());
				self::assertGreaterThan(0, $board->getLastModified());
				$board->setId(42);
				return $board;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				42,
				Change::ENTITY_BOARD,
				42,
				Change::ACTION_CREATE,
				'alice'
			)
			->willReturn(new Change());

		$board = $this->service->create('My board', 'AABBCC', 'alice');
		self::assertSame(42, $board->getId());
	}

	public function testCreateRejectsEmptyTitle(): void {
		$this->boardMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->create('   ', null, 'alice');
	}

	public function testCreateRejectsInvalidColor(): void {
		$this->boardMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create('My board', '#AABBCC', 'alice');
	}

	public function testUpdateAssertsManagePermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->update(1, 'New title', null, null, 'bob');
	}

	public function testUpdateAppliesFieldsAndWritesChangeRow(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_BOARD,
				1,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn(new Change());

		$updated = $this->service->update(1, 'New title', '112233', true, 'alice');
		self::assertSame('New title', $updated->getTitle());
		self::assertSame('112233', $updated->getColor());
		self::assertTrue($updated->getArchived());
		self::assertGreaterThan(0, $updated->getLastModified());
	}

	public function testUpdateSetsValidEstimateScale(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(1, null, null, null, 'alice', 'tshirt');
		self::assertSame('tshirt', $updated->getEstimateScale());
	}

	public function testUpdateRejectsUnknownEstimateScale(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(1, null, null, null, 'alice', 'made-up-scale');
	}

	public function testDeleteSoftDeletesAndWritesChangeRow(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->boardMapper->expects(self::once())
			->method('update')
			->with(self::callback(static fn (Board $b): bool => $b->getDeletedAt() > 0))
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_BOARD,
				1,
				Change::ACTION_DELETE,
				'alice'
			)
			->willReturn(new Change());

		$deleted = $this->service->delete(1, 'alice');
		self::assertGreaterThan(0, $deleted->getDeletedAt());
	}

	public function testFindAllUsesGroupIdsFromPermissionService(): void {
		$boards = [$this->board()];
		$this->permissionService->method('getUserGroupIds')->with('alice')->willReturn(['devs']);
		$this->boardMapper->expects(self::once())
			->method('findAllForUser')
			->with('alice', ['devs'])
			->willReturn($boards);

		self::assertSame($boards, $this->service->findAll('alice'));
	}
}

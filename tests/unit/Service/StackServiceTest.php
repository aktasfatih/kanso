<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SortKeyService;
use OCA\Kanso\Service\StackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StackServiceTest extends TestCase {
	private StackMapper&MockObject $stackMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private IDBConnection&MockObject $db;
	private StackService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new StackService(
			$this->stackMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			new SortKeyService(),
			$this->db
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
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_STACK,
				7,
				Change::ACTION_CREATE,
				'alice'
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
		$this->changeNotifier->expects(self::once())
			->method('notify')
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
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, 'To do', 'bob');
	}

	public function testUpdateAppliesFieldsAndWritesChangeRow(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_STACK,
				5,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn(new Change());

		$updated = $this->service->update(5, 'Renamed', true, null, null, 'alice');
		self::assertSame('Renamed', $updated->getTitle());
		self::assertTrue($updated->getArchived());
	}

	public function testUpdateSetsRole(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_STACK, 5, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$updated = $this->service->update(5, null, null, Stack::ROLE_DONE, null, 'alice');
		self::assertSame(Stack::ROLE_DONE, $updated->getRole());
	}

	public function testUpdateRejectsOutOfRangeRole(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->update(5, null, null, 6, null, 'alice');
	}

	public function testUpdateRejectsNegativeRole(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(5, null, null, -1, null, 'alice');
	}

	public function testUpdateSetsWipLimit(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(5, null, null, null, 3, 'alice');
		self::assertSame(3, $updated->getWipLimit());
	}

	public function testUpdateClearsWipLimitWithZero(): void {
		$stack = $this->stack();
		$stack->setWipLimit(5);
		$this->stackMapper->method('find')->with(5)->willReturn($stack);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(5, null, null, null, 0, 'alice');
		self::assertSame(0, $updated->getWipLimit());
	}

	public function testUpdateRejectsNegativeWipLimit(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(5, null, null, null, -2, 'alice');
	}

	public function testUpdateAssertsEditPermission(): void {
		$board = $this->board();
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->stackMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->update(5, null, null, Stack::ROLE_DONE, null, 'bob');
	}

	public function testDeleteSoftDeletesAndWritesChangeRow(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->expects(self::once())
			->method('update')
			->with(self::callback(static fn (Stack $s): bool => $s->getDeletedAt() > 0))
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_STACK,
				5,
				Change::ACTION_DELETE,
				'alice'
			)
			->willReturn(new Change());

		$deleted = $this->service->delete(5, 'alice');
		self::assertGreaterThan(0, $deleted->getDeletedAt());
	}

	// ---- restore ----------------------------------------------------------

	public function testRestoreClearsDeletedAtAndWritesCreateChange(): void {
		$deleted = $this->stack();
		$deleted->setDeletedAt(1234);
		$this->stackMapper->method('find')->with(5)->willReturn($deleted);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'alice', PermissionService::PERMISSION_EDIT);
		$this->stackMapper->expects(self::once())
			->method('update')
			->with(self::callback(static fn (Stack $s): bool => $s->getDeletedAt() === 0))
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_STACK, 5, Change::ACTION_CREATE, 'alice')
			->willReturn(new Change());

		$restored = $this->service->restore(5, 'alice');
		self::assertSame(0, $restored->getDeletedAt());
	}

	public function testRestoreRejectsLiveStack(): void {
		// A live stack must not be resurrected by a stale undo.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->stackMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(DoesNotExistException::class);
		$this->service->restore(5, 'alice');
	}

	public function testRestoreAssertsEditPermission(): void {
		$deleted = $this->stack();
		$deleted->setDeletedAt(1234);
		$this->stackMapper->method('find')->with(5)->willReturn($deleted);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->stackMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->restore(5, 'mallory');
	}

	// ---- move -------------------------------------------------------------

	public function testMoveBetweenNeighboursUsesMidpointKeyInsideTransaction(): void {
		// Board order: 5(I), 6(J), 7(K) — move 7 directly after 5.
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, 1, 'K'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(5, 1, 'I'),
			$this->stack(6, 1, 'J'),
			$this->stack(7, 1, 'K'),
		]);
		$this->stackMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_STACK,
				7,
				Change::ACTION_MOVE,
				'alice'
			)
			->willReturn(new Change());
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$moved = $this->service->move(7, 5, 'alice');
		// between('I', 'J') === 'II'
		self::assertSame('II', $moved->getSortKey());
	}

	public function testMoveToFrontUsesBeforeFirstKey(): void {
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, 1, 'K'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(5, 1, 'I'),
			$this->stack(7, 1, 'K'),
		]);
		$this->stackMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_STACK, 7, Change::ACTION_MOVE, 'alice')
			->willReturn(new Change());

		$moved = $this->service->move(7, null, 'alice');
		// before('I') === 'H'
		self::assertSame('H', $moved->getSortKey());
	}

	public function testMoveAfterLastStackUsesAfterKey(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5, 1, 'I'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(5, 1, 'I'),
			$this->stack(6, 1, 'J'),
		]);
		$this->stackMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(5, 6, 'alice');
		// after('J') === 'K'
		self::assertSame('K', $moved->getSortKey());
	}

	public function testMoveAssertsEditPermission(): void {
		$board = $this->board();
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->db->expects(self::never())->method('beginTransaction');
		$this->stackMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->move(5, null, 'bob');
	}

	public function testMoveRejectsSelfAnchor(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(5, 1, 'I'),
			$this->stack(6, 1, 'J'),
		]);
		$this->db->expects(self::never())->method('beginTransaction');
		$this->stackMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->move(5, 5, 'alice');
	}

	public function testMoveRejectsAnchorNotOnBoard(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(5, 1, 'I'),
		]);
		$this->db->expects(self::never())->method('beginTransaction');
		$this->stackMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->move(5, 99, 'alice');
	}

	public function testMovePropagatesOverflowAndRollsBack(): void {
		// after('Z' x 64) would need a 65th character → OverflowException.
		$maxKey = str_repeat('Z', 64);
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5, 1, 'I'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(5, 1, 'I'),
			$this->stack(6, 1, $maxKey),
		]);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->stackMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(\OverflowException::class);
		$this->service->move(5, 6, 'alice');
	}

	public function testMoveRollsBackTransactionOnMapperFailure(): void {
		$this->stackMapper->method('find')->with(7)->willReturn($this->stack(7, 1, 'K'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(5, 1, 'I'),
			$this->stack(7, 1, 'K'),
		]);
		$this->stackMapper->method('update')
			->willThrowException(new \RuntimeException('db gone'));
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(\RuntimeException::class);
		$this->service->move(7, 5, 'alice');
	}
}

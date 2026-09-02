<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardGroup;
use OCA\Kanso\Db\BoardGroupMapper;
use OCA\Kanso\Db\BoardGroupMember;
use OCA\Kanso\Db\BoardGroupMemberMapper;
use OCA\Kanso\Service\BoardGroupService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardGroupServiceTest extends TestCase {
	private BoardGroupMapper&MockObject $groupMapper;
	private BoardGroupMemberMapper&MockObject $memberMapper;
	private BoardService&MockObject $boardService;
	private BoardGroupService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupMapper = $this->createMock(BoardGroupMapper::class);
		$this->memberMapper = $this->createMock(BoardGroupMemberMapper::class);
		$this->boardService = $this->createMock(BoardService::class);
		$this->service = new BoardGroupService(
			$this->groupMapper,
			$this->memberMapper,
			$this->boardService,
		);
	}

	private function group(int $id, string $uid = 'alice', string $name = 'Work', int $sort = 0): BoardGroup {
		$group = new BoardGroup();
		$group->setId($id);
		$group->setUid($uid);
		$group->setName($name);
		$group->setSort($sort);
		return $group;
	}

	private function board(int $id): Board {
		$board = new Board();
		$board->setId($id);
		$board->setDeletedAt(0);
		return $board;
	}

	private function member(int $id, string $uid, int $groupId, int $boardId): BoardGroupMember {
		$m = new BoardGroupMember();
		$m->setId($id);
		$m->setUid($uid);
		$m->setGroupId($groupId);
		$m->setBoardId($boardId);
		return $m;
	}

	// ── create ────────────────────────────────────────────────────────────────

	public function testCreateGroupAppendsAfterExistingFolders(): void {
		$this->groupMapper->method('findByUser')->with('alice')->willReturn([$this->group(1)]);
		$this->groupMapper->method('maxSort')->with('alice')->willReturn(4);
		$this->groupMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (BoardGroup $g): BoardGroup {
				self::assertSame('alice', $g->getUid());
				self::assertSame('Backlog', $g->getName());
				self::assertSame(5, $g->getSort());
				$g->setId(9);
				return $g;
			});

		$created = $this->service->createGroup('alice', '  Backlog  ');
		self::assertSame(9, $created->getId());
	}

	public function testCreateGroupRejectsEmptyName(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->createGroup('alice', '   ');
	}

	// ── rename (ownership) ─────────────────────────────────────────────────────

	public function testRenameGroupUpdatesOwnedFolder(): void {
		$group = $this->group(3, 'alice', 'Old');
		$this->groupMapper->method('findOwned')->with(3, 'alice')->willReturn($group);
		$this->groupMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (BoardGroup $g): BoardGroup {
				self::assertSame('New name', $g->getName());
				return $g;
			});

		$this->service->renameGroup('alice', 3, 'New name');
	}

	public function testRenameGroupOfAnotherUserIsDenied(): void {
		// findOwned scopes by uid, so another user's folder id resolves to null.
		$this->groupMapper->method('findOwned')->with(3, 'mallory')->willReturn(null);
		$this->groupMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->renameGroup('mallory', 3, 'Hijack');
	}

	// ── delete (ungroups, never deletes boards) ────────────────────────────────

	public function testDeleteGroupUngroupsItsBoardsThenRemovesFolder(): void {
		$group = $this->group(5, 'alice');
		$this->groupMapper->method('findOwned')->with(5, 'alice')->willReturn($group);
		$this->memberMapper->expects(self::once())->method('deleteByGroup')->with(5, 'alice')->willReturn(2);
		$this->groupMapper->expects(self::once())->method('delete')->with($group);

		$this->service->deleteGroup('alice', 5);
	}

	// ── assign (READ-gated + idempotent) ───────────────────────────────────────

	public function testAssignBoardInsertsMembershipWhenReadable(): void {
		$this->groupMapper->method('findOwned')->with(2, 'alice')->willReturn($this->group(2));
		// find() asserts READ; returning a board means it passed.
		$this->boardService->expects(self::once())->method('find')->with(7, 'alice')->willReturn($this->board(7));
		$this->memberMapper->method('findForBoard')->with('alice', 7)->willReturn(null);
		$this->memberMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (BoardGroupMember $m): BoardGroupMember {
				self::assertSame('alice', $m->getUid());
				self::assertSame(2, $m->getGroupId());
				self::assertSame(7, $m->getBoardId());
				return $m;
			});

		$this->service->assignBoard('alice', 2, 7);
	}

	public function testAssignBoardIsIdempotentWhenAlreadyInThatFolder(): void {
		$this->groupMapper->method('findOwned')->with(2, 'alice')->willReturn($this->group(2));
		$this->boardService->method('find')->with(7, 'alice')->willReturn($this->board(7));
		$this->memberMapper->method('findForBoard')->with('alice', 7)
			->willReturn($this->member(1, 'alice', 2, 7));
		// Already in folder 2 → no insert, no update.
		$this->memberMapper->expects(self::never())->method('insert');
		$this->memberMapper->expects(self::never())->method('update');

		$this->service->assignBoard('alice', 2, 7);
	}

	public function testAssignBoardMovesBetweenFolders(): void {
		$this->groupMapper->method('findOwned')->with(3, 'alice')->willReturn($this->group(3));
		$this->boardService->method('find')->with(7, 'alice')->willReturn($this->board(7));
		$existing = $this->member(1, 'alice', 2, 7);
		$this->memberMapper->method('findForBoard')->with('alice', 7)->willReturn($existing);
		$this->memberMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (BoardGroupMember $m): BoardGroupMember {
				self::assertSame(3, $m->getGroupId());
				return $m;
			});

		$this->service->assignBoard('alice', 3, 7);
	}

	public function testAssignBoardYouCannotReadIsDenied(): void {
		$this->groupMapper->method('findOwned')->with(2, 'alice')->willReturn($this->group(2));
		// find() enforces board READ and throws for an unreadable board.
		$this->boardService->method('find')->with(99, 'alice')
			->willThrowException(new NotPermittedException('nope'));
		$this->memberMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->assignBoard('alice', 2, 99);
	}

	public function testAssignBoardIntoAnotherUsersFolderIsDenied(): void {
		$this->groupMapper->method('findOwned')->with(2, 'mallory')->willReturn(null);
		$this->boardService->expects(self::never())->method('find');
		$this->memberMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->assignBoard('mallory', 2, 7);
	}

	public function testAssignMissingBoardThrows(): void {
		$this->groupMapper->method('findOwned')->with(2, 'alice')->willReturn($this->group(2));
		$this->boardService->method('find')->with(50, 'alice')
			->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(DoesNotExistException::class);
		$this->service->assignBoard('alice', 2, 50);
	}

	// ── unassign (idempotent, own row only) ────────────────────────────────────

	public function testUnassignRemovesOwnMembership(): void {
		$existing = $this->member(1, 'alice', 2, 7);
		$this->memberMapper->method('findForBoard')->with('alice', 7)->willReturn($existing);
		$this->memberMapper->expects(self::once())->method('delete')->with($existing);

		$this->service->unassignBoard('alice', 7);
	}

	public function testUnassignIsNoopWhenNotFiled(): void {
		$this->memberMapper->method('findForBoard')->with('alice', 7)->willReturn(null);
		$this->memberMapper->expects(self::never())->method('delete');

		$this->service->unassignBoard('alice', 7);
	}

	// ── list (per-user, readable-scoped, batched) ──────────────────────────────

	public function testListGroupsSurfacesReadableMembersOnly(): void {
		$this->groupMapper->method('findByUser')->with('alice')
			->willReturn([$this->group(1, 'alice', 'Work', 0), $this->group(2, 'alice', 'Personal', 1)]);
		// Readable board set is derived from findAll (ACL-authorized).
		$this->boardService->method('findAllActive')->with('alice')
			->willReturn([$this->board(10), $this->board(11), $this->board(12)]);
		// ONE batched lookup over the readable ids.
		$this->memberMapper->expects(self::once())
			->method('findGroupIdsByBoards')->with('alice', [10, 11, 12])
			->willReturn([10 => 1, 11 => 1, 12 => 2]);

		$out = $this->service->listGroups('alice');

		self::assertCount(2, $out);
		self::assertSame(1, $out[0]['id']);
		self::assertSame('Work', $out[0]['name']);
		self::assertSame([10, 11], $out[0]['boardIds']);
		self::assertSame(2, $out[1]['id']);
		self::assertSame([12], $out[1]['boardIds']);
	}

	public function testListGroupsSkipsBoardsTheUserHasArchived(): void {
		// #10126: an archived board leaves its folder's listing. The mock mirrors
		// the real BoardService - findAll() STILL carries the archived board (the
		// boards page's Archived tab is built on it), only findAllActive() drops
		// it - so reverting the service to findAll() puts board 11 back in the
		// batched lookup and this goes red. The membership row itself is kept, so
		// unarchiving restores the board to its folder.
		$active = $this->board(10);
		$archived = $this->board(11);
		$archived->setArchived(true);
		$this->groupMapper->method('findByUser')->with('alice')
			->willReturn([$this->group(1, 'alice', 'Work', 0)]);
		$this->boardService->method('findAll')->with('alice')->willReturn([$active, $archived]);
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$active]);
		$this->memberMapper->expects(self::once())
			->method('findGroupIdsByBoards')->with('alice', [10])
			->willReturn([10 => 1]);

		// The active board is still filed under the folder - both halves matter,
		// or a filter that dropped everything would pass too.
		$out = $this->service->listGroups('alice');
		self::assertSame([10], $out[0]['boardIds']);
	}

	public function testListGroupsEmptyWhenNoFolders(): void {
		$this->groupMapper->method('findByUser')->with('alice')->willReturn([]);
		$this->boardService->expects(self::never())->method('findAllActive');
		self::assertSame([], $this->service->listGroups('alice'));
	}

	// ── reorder ────────────────────────────────────────────────────────────────

	public function testReorderGroupsAppliesSequentialSortForOwnedIds(): void {
		$g1 = $this->group(1, 'alice', 'A', 0);
		$g2 = $this->group(2, 'alice', 'B', 1);
		$g3 = $this->group(3, 'alice', 'C', 2);
		// findByUser is called by reorder AND by the trailing listGroups.
		$this->groupMapper->method('findByUser')->with('alice')->willReturn([$g1, $g2, $g3]);
		$this->boardService->method('findAllActive')->with('alice')->willReturn([]);
		$this->memberMapper->method('findGroupIdsByBoards')->willReturn([]);

		$updated = [];
		$this->groupMapper->method('update')->willReturnCallback(static function (BoardGroup $g) use (&$updated): BoardGroup {
			$updated[$g->getId()] = $g->getSort();
			return $g;
		});

		// Request order [3,1] - g3→0, g1→1; g2 omitted → keeps a slot after (→2).
		$this->service->reorderGroups('alice', [3, 1, 999]);

		self::assertSame(0, $updated[3]);
		self::assertSame(1, $updated[1]);
		self::assertSame(2, $updated[2]);
	}
}

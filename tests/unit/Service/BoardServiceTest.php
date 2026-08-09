<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardGroupMemberMapper;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\BoardPinMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
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
	private CardMapper&MockObject $cardMapper;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private BoardGroupMemberMapper&MockObject $boardGroupMemberMapper;
	private BoardPinMapper&MockObject $boardPinMapper;
	private BoardAccess&MockObject $boardAccess;
	private BoardService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->boardGroupMemberMapper = $this->createMock(BoardGroupMemberMapper::class);
		$this->boardPinMapper = $this->createMock(BoardPinMapper::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->service = new BoardService(
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->cardMapper,
			$this->cardReviewMapper,
			$this->boardGroupMemberMapper,
			$this->boardPinMapper,
			$this->boardAccess
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
				// Prefix defaults to the first letters of the title, uppercased.
				self::assertSame('MYBOA', $board->getPrefix());
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

	public function testUpdateNormalizesPrefix(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// Lower-case + spaces + over-length are normalized (uppercased, alnum, capped at 5).
		$updated = $this->service->update(1, null, null, null, 'alice', null, null, 'my proj');
		self::assertSame('MYPRO', $updated->getPrefix());
	}

	public function testUpdateRejectsEmptyPrefix(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		// A prefix that reduces to nothing (only punctuation) is invalid.
		$this->service->update(1, null, null, null, 'alice', null, null, '---');
	}

	public function testUpdateSetsValidBackgroundAndWritesChangeRow(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->method('update')->willReturnArgument(0);
		// The background change still appends a kanso_changes row (ETag/realtime).
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_BOARD, 1, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$updated = $this->service->update(1, null, null, null, 'alice', null, null, null, 'ocean');
		self::assertSame('ocean', $updated->getBackground());
	}

	public function testUpdateClearsBackgroundWithEmptyString(): void {
		$board = $this->board();
		$board->setBackground('ocean');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// An empty string clears the background (stored as null).
		$updated = $this->service->update(1, null, null, null, 'alice', null, null, null, '');
		self::assertNull($updated->getBackground());
	}

	public function testUpdateRejectsUnknownBackground(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		// A token outside the curated allow-list is rejected (no CSS-injection surface).
		$this->service->update(1, null, null, null, 'alice', null, null, null, 'url(javascript:alert(1))');
	}

	public function testUpdateBackgroundAssertsManagePermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		// A non-MANAGE user cannot set the background.
		$this->service->update(1, null, null, null, 'bob', null, null, null, 'ocean');
	}

	public function testUpdateSetsValidChatUrlAndSerializesIt(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_BOARD, 1, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$updated = $this->service->update(1, null, null, null, 'alice', null, null, null, null, 'https://cloud.example.com/call/abc123');
		self::assertSame('https://cloud.example.com/call/abc123', $updated->getChatUrl());
		// The chat link rides the regular board payload, visible to all members.
		self::assertSame('https://cloud.example.com/call/abc123', $updated->jsonSerialize()['chatUrl']);
	}

	public function testUpdateClearsChatUrlWithEmptyString(): void {
		$board = $this->board();
		$board->setChatUrl('https://cloud.example.com/call/abc123');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// An empty (or whitespace-only) string clears the link (stored as null).
		$updated = $this->service->update(1, null, null, null, 'alice', null, null, null, null, '   ');
		self::assertNull($updated->getChatUrl());
		self::assertNull($updated->jsonSerialize()['chatUrl']);
	}

	/**
	 * The scheme allow-list is the XSS gate: the client renders the chat link
	 * as an <a href>, so anything but plain http(s) must be rejected.
	 */
	public static function invalidChatUrlProvider(): array {
		return [
			'javascript scheme' => ['javascript:alert(1)'],
			'data scheme' => ['data:text/html,<script>alert(1)</script>'],
			'scheme-relative' => ['//evil.example.com/room'],
			'no scheme' => ['cloud.example.com/call/abc123'],
			'embedded whitespace' => ['https://cloud.example.com/call/a b'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidChatUrlProvider')]
	public function testUpdateRejectsInvalidChatUrl(string $chatUrl): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->update(1, null, null, null, 'alice', null, null, null, null, $chatUrl);
	}

	public function testUpdateRejectsOverlongChatUrl(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		// Longer than the 4000-char chat_url column.
		$this->service->update(1, null, null, null, 'alice', null, null, null, null, 'https://cloud.example.com/' . str_repeat('a', 4000));
	}

	public function testUpdateChatUrlAssertsManagePermission(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		// A non-MANAGE user cannot set the chat link.
		$this->service->update(1, null, null, null, 'bob', null, null, null, null, 'https://cloud.example.com/call/abc123');
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

	public function testFindAllWithStatsStitchesBatchedAggregatesOntoEachBoard(): void {
		$b1 = $this->board(1, 'alice');
		$b2 = $this->board(2, 'alice');
		$this->permissionService->method('getUserGroupIds')->with('alice')->willReturn([]);
		$this->boardMapper->method('findAllForUser')->with('alice', [])->willReturn([$b1, $b2]);

		// The viewer's per-board role map (ONE batched fetch, #3743) scopes every
		// aggregate below.
		$roles = [1 => ViewerContext::ROLE_INTERNAL, 2 => ViewerContext::ROLE_EXTERNAL];
		$this->boardAccess->expects(self::once())->method('rolesFor')
			->with([$b1, $b2], 'alice')->willReturn($roles);

		// The aggregates are called ONCE each with the full readable board-id set -
		// a fixed query count, not one-query-per-board.
		$this->cardMapper->expects(self::once())
			->method('countByBoards')->with([1, 2], 'alice', $roles)->willReturn([1 => 5, 2 => 0]);
		$this->cardMapper->expects(self::once())
			->method('doneRatioByBoards')->with([1, 2], 'alice', $roles)->willReturn([1 => ['total' => 5, 'done' => 2]]);
		$this->cardMapper->expects(self::once())
			->method('overdueCountByBoards')->with([1, 2], self::anything(), 'alice', $roles)->willReturn([1 => 3]);
		$this->cardReviewMapper->expects(self::once())
			->method('needsReviewCountByBoards')->with([1, 2], 'alice', $roles)->willReturn([1 => 4]);
		// The per-user folder map is ONE batched lookup over the same readable set;
		// board 1 is filed under folder 7, board 2 is Ungrouped (absent).
		$this->boardGroupMemberMapper->expects(self::once())
			->method('findGroupIdsByBoards')->with('alice', [1, 2])->willReturn([1 => 7]);
		// The per-user pin map is ONE batched lookup over the same readable set;
		// board 1 is pinned, board 2 is not (absent from the map → false).
		$this->boardPinMapper->expects(self::once())
			->method('pinnedMap')->with('alice', [1, 2])->willReturn([1 => true]);
		// The permission map is ONE batched call over the whole board set (#3750)
		// - never a per-board getPermissions() call. Board 1: full (owner-like),
		// board 2: read-only (shared).
		$this->permissionService->expects(self::once())
			->method('getPermissionsForBoards')
			->with([$b1, $b2], 'alice')
			->willReturn([
				1 => PermissionService::PERMISSION_ALL,
				2 => PermissionService::PERMISSION_READ,
			]);

		$result = $this->service->findAllWithStats('alice');

		self::assertCount(2, $result);
		self::assertSame(1, $result[0]['id']);
		self::assertSame(7, $result[0]['groupId']);
		self::assertTrue($result[0]['pinned']);
		// The bitmask is stitched onto the payload so the tile menu can gate
		// manager-only entries.
		self::assertSame(PermissionService::PERMISSION_ALL, $result[0]['permissions']);
		self::assertSame([
			'cardCount' => 5,
			'doneCount' => 2,
			'progress' => 40,
			'needsReview' => 4,
			'overdue' => 3,
		], $result[0]['stats']);
		// A board absent from every aggregate map defaults to all-zero, 0 %.
		self::assertSame(2, $result[1]['id']);
		// A board in no folder is Ungrouped (groupId null).
		self::assertNull($result[1]['groupId']);
		// A board absent from the pin map is not pinned by this user.
		self::assertFalse($result[1]['pinned']);
		// A shared (non-owner) board carries this user's reduced bitmask.
		self::assertSame(PermissionService::PERMISSION_READ, $result[1]['permissions']);
		self::assertSame([
			'cardCount' => 0,
			'doneCount' => 0,
			'progress' => 0,
			'needsReview' => 0,
			'overdue' => 0,
		], $result[1]['stats']);
	}

	public function testFindAllWithStatsPassesEmptySetWhenUserHasNoBoards(): void {
		$this->permissionService->method('getUserGroupIds')->with('alice')->willReturn([]);
		$this->boardMapper->method('findAllForUser')->with('alice', [])->willReturn([]);

		$this->cardMapper->expects(self::once())->method('countByBoards')->with([])->willReturn([]);
		$this->cardMapper->expects(self::once())->method('doneRatioByBoards')->with([])->willReturn([]);
		$this->cardMapper->expects(self::once())->method('overdueCountByBoards')->with([])->willReturn([]);
		$this->cardReviewMapper->expects(self::once())->method('needsReviewCountByBoards')->with([])->willReturn([]);
		$this->boardGroupMemberMapper->expects(self::once())->method('findGroupIdsByBoards')->with('alice', [])->willReturn([]);
		$this->boardPinMapper->expects(self::once())->method('pinnedMap')->with('alice', [])->willReturn([]);
		$this->permissionService->expects(self::once())
			->method('getPermissionsForBoards')->with([], 'alice')->willReturn([]);

		self::assertSame([], $this->service->findAllWithStats('alice'));
	}
}

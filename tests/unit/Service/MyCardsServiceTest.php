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
		// The default page load stays ONE query for open work (#10061): adding
		// the recently-done section must never cost the default path a second
		// round trip. Flip findMine to call the done query and this goes red.
		$this->cardMapper->expects($this->never())->method('findAssignedDoneSinceInBoards');

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

	// ---- recently done: the opt-in second feed (#10061) --------------------
	//
	// A separate query, never issued by the default page load. The product
	// requirement is explicit that completed work "shouldn't list everything
	// unless we ask for it", so these pin the two bounds that make asking for
	// it cheap: a recency window AND a row cap.

	/**
	 * Runs findMineRecentlyDone and returns the arguments the mapper was called
	 * with, so a test can assert on the cutoff the service computed.
	 *
	 * @param list<array<string, mixed>> $rows what the mapper hands back
	 * @return array{0: array<int, mixed>, 1: array{cards: list<array<string, mixed>>, truncated: bool, limit: int, windowDays: int}}
	 */
	private function captureRecentlyDone(string $uid, array $rows): array {
		$seen = [];
		$this->cardMapper->expects($this->once())
			->method('findAssignedDoneSinceInBoards')
			->willReturnCallback(function (...$args) use (&$seen, $rows) {
				$seen = $args;
				return $rows;
			});

		$feed = $this->service->findMineRecentlyDone($uid);

		return [$seen, $feed];
	}

	public function testFindMineRecentlyDoneRestrictsToReadableBoardsAndTheUser(): void {
		// Denial: a completed card on a board outside findAll()'s readable set
		// can never be returned, because that board id never reaches the query.
		// Same boundary as the open feed - assignment grants no visibility.
		$this->boardService->expects($this->once())
			->method('findAll')
			->with('alice')
			->willReturn([$this->board(3), $this->board(9)]);

		$roles = [3 => ViewerContext::ROLE_INTERNAL, 9 => ViewerContext::ROLE_EXTERNAL];
		$this->boardAccess->expects($this->once())->method('rolesFor')->willReturn($roles);

		[$args] = $this->captureRecentlyDone('alice', []);

		$this->assertSame(['alice'], $args[0], 'only the viewer\'s own identity is matched');
		$this->assertSame([3, 9], $args[1], 'only the readable board set is queried');
		$this->assertNotContains(12, $args[1], 'a board the viewer cannot read must never be queried');
		$this->assertSame('alice', $args[2]);
		$this->assertSame($roles, $args[3], 'the per-board roles scope the visibility rule');
	}

	public function testFindMineRecentlyDoneWithNoReadableBoardsQueriesTheEmptySet(): void {
		// No readable boards → an empty board set, which the mapper
		// short-circuits. Nothing assigned can leak through the back door.
		$this->boardService->method('findAll')->willReturn([]);
		$this->boardAccess->method('rolesFor')->willReturn([]);

		[$args, $feed] = $this->captureRecentlyDone('bob', []);

		$this->assertSame([], $args[1]);
		$this->assertSame([], $feed['cards']);
	}

	public function testFindMineRecentlyDoneQueriesOnlyInsideTheRecencyWindow(): void {
		// THE bound that makes this cheap. A card completed just inside the
		// window is within the queried range; one completed just outside it is
		// not - so a user with years of completed work still gets a query that
		// touches a fortnight. Widen or narrow RECENT_DONE_WINDOW_DAYS and one
		// of these two assertions fails.
		$this->boardService->method('findAll')->willReturn([$this->board(3)]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);

		$now = time();
		[$args, $feed] = $this->captureRecentlyDone('alice', []);
		$doneSince = $args[4];

		// The window is a LITERAL here, never derived from the constant under
		// test - deriving it would make every assertion below move with the
		// constant and prove nothing at all.
		$window = 14 * 86400;
		$this->assertSame(14, MyCardsService::RECENT_DONE_WINDOW_DAYS, 'the chosen window is a fortnight');
		$this->assertEqualsWithDelta($now - $window, $doneSince, 5, 'the cutoff is now minus the window');

		$justInside = $now - $window + 3600; // completed an hour before the edge
		$justOutside = $now - $window - 3600; // completed an hour past it
		$this->assertGreaterThanOrEqual($doneSince, $justInside, 'a card completed just inside the window is queried');
		$this->assertLessThan($doneSince, $justOutside, 'a card completed just outside the window is not');

		$this->assertSame(14, $feed['windowDays'], 'the window is reported to the client');
	}

	public function testFindMineRecentlyDoneCapsTheRowsAndSaysSo(): void {
		// The second bound. The window alone has no ceiling for someone who
		// closes hundreds of cards a fortnight, so the row cap applies too -
		// and, like the open feed's, it is reported rather than silent.
		$this->boardService->method('findAll')->willReturn([$this->board(3)]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);

		[$args, $feed] = $this->captureRecentlyDone('alice', $this->rows(MyCardsService::RECENT_DONE_LIMIT + 1));

		// A literal, like the window: a cap that quietly grew to five figures
		// would still satisfy an assertion written in terms of itself.
		$this->assertSame(50, MyCardsService::RECENT_DONE_LIMIT, 'the section is capped at 50 rows');
		// LIMIT + 1 is the probe that makes truncation detectable at all.
		$this->assertSame(MyCardsService::RECENT_DONE_LIMIT + 1, $args[5]);
		$this->assertTrue($feed['truncated']);
		$this->assertSame(MyCardsService::RECENT_DONE_LIMIT, $feed['limit']);
		// The probe row is never handed to the client.
		$this->assertCount(MyCardsService::RECENT_DONE_LIMIT, $feed['cards']);
	}

	public function testFindMineRecentlyDoneAtExactlyTheLimitIsNotTruncated(): void {
		$this->boardService->method('findAll')->willReturn([$this->board(3)]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);

		[, $feed] = $this->captureRecentlyDone('alice', $this->rows(MyCardsService::RECENT_DONE_LIMIT));

		$this->assertFalse($feed['truncated']);
		$this->assertCount(MyCardsService::RECENT_DONE_LIMIT, $feed['cards']);
	}

	public function testFindMineRecentlyDoneDoesNotTouchTheOpenFeed(): void {
		// The two feeds are independent queries: asking for completed work must
		// not re-run (or replace) the open one.
		$this->boardService->method('findAll')->willReturn([$this->board(3)]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);
		$this->cardMapper->expects($this->never())->method('findAssignedInBoards');

		$this->captureRecentlyDone('alice', []);
	}
}

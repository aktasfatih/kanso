<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\CardSummaryService;
use OCA\Kanso\Service\ViewService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ViewServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private CardMapper&MockObject $cardMapper;
	private CardSummaryService&MockObject $cardSummaryService;
	private BoardAccess&MockObject $boardAccess;
	private ViewService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardSummaryService = $this->createMock(CardSummaryService::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->service = new ViewService(
			$this->boardService,
			$this->cardMapper,
			$this->cardSummaryService,
			$this->boardAccess,
		);
	}

	private function board(int $id, string $title): Board {
		$board = new Board();
		$board->setId($id);
		$board->setTitle($title);
		$board->setOwner('alice');
		return $board;
	}

	private function summaryCard(int $id, int $boardId): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		return $card;
	}

	public function testFindMineReturnsCardsFromEveryReadableBoardTaggedWithBoardIdentity(): void {
		$b3 = $this->board(3, 'Alpha');
		$b9 = $this->board(9, 'Beta');
		$this->boardService->expects(self::once())
			->method('findAll')->with('alice')->willReturn([$b3, $b9]);

		$ctx3 = ViewerContext::forMember('alice', 3, ViewerContext::ROLE_INTERNAL, true);
		$ctx9 = ViewerContext::forMember('alice', 9, ViewerContext::ROLE_EXTERNAL, false);
		$this->boardAccess->method('contextFor')->willReturnMap([
			[$b3, 'alice', $ctx3],
			[$b9, 'alice', $ctx9],
		]);

		// The summary query runs per readable board under that board's viewer ctx.
		$this->cardMapper->method('findSummariesByBoard')
			->willReturnCallback(fn (int $boardId): array => [$this->summaryCard($boardId === 3 ? 11 : 22, $boardId)]);
		// The shared enrichment is exercised (mocked here); it returns the summary
		// arrays the client filters/groups over, keyed off the board it ran for.
		$this->cardSummaryService->method('serialize')
			->willReturnCallback(fn (int $boardId): array => [['id' => $boardId === 3 ? 11 : 22]]);

		$rows = $this->service->findMine('alice');

		self::assertCount(2, $rows);
		self::assertSame(11, $rows[0]['id']);
		self::assertSame(3, $rows[0]['boardId']);
		self::assertSame('Alpha', $rows[0]['boardTitle']);
		self::assertSame(22, $rows[1]['id']);
		self::assertSame(9, $rows[1]['boardId']);
		self::assertSame('Beta', $rows[1]['boardTitle']);
	}

	/**
	 * The ACL boundary (#3815, REQUIRED leak-denial): a View run by user A must
	 * NEVER surface a card from a board A cannot read. findAll() is the single
	 * readable-set gate - the service must query ONLY the boards it returns and
	 * never touch a board absent from that set, so an unreadable board's cards
	 * can never enter the feed.
	 */
	public function testFindMineNeverQueriesABoardOutsideTheReadableSet(): void {
		// alice can read board 3 only; board 7 (which she cannot read) exists in
		// the system but is absent from findAll()'s readable set.
		$b3 = $this->board(3, 'Readable');
		$this->boardService->method('findAll')->with('alice')->willReturn([$b3]);

		$ctx3 = ViewerContext::forMember('alice', 3, ViewerContext::ROLE_INTERNAL, true);
		$this->boardAccess->method('contextFor')->with($b3, 'alice')->willReturn($ctx3);

		// The query is asked ONLY for board 3, and with board 3's viewer context -
		// never for the unreadable board 7 under any context.
		$this->cardMapper->expects(self::once())
			->method('findSummariesByBoard')
			->with(3, $ctx3)
			->willReturn([$this->summaryCard(11, 3)]);
		$this->cardSummaryService->method('serialize')
			->willReturn([['id' => 11, 'title' => 'Only mine']]);

		$rows = $this->service->findMine('alice');

		$boardIds = array_map(static fn (array $c): int => $c['boardId'], $rows);
		self::assertSame([3], array_values(array_unique($boardIds)));
		// No row from the unreadable board 7 leaked through.
		self::assertNotContains(7, $boardIds);
	}

	public function testFindMineEmptyWhenNoReadableBoards(): void {
		$this->boardService->method('findAll')->with('bob')->willReturn([]);
		$this->boardAccess->expects(self::never())->method('contextFor');
		$this->cardMapper->expects(self::never())->method('findSummariesByBoard');

		self::assertSame([], $this->service->findMine('bob'));
	}
}

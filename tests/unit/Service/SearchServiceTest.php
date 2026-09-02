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
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\SearchService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchServiceTest extends TestCase {
	private BoardService&MockObject $boardService;
	private CardMapper&MockObject $cardMapper;
	private CommentMapper&MockObject $commentMapper;
	private IDBConnection&MockObject $db;
	private BoardAccess&MockObject $boardAccess;
	private SearchService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		// Mirror the platform helper's backslash-escaping of LIKE wildcards.
		$this->db->method('escapeLikeParameter')->willReturnCallback(
			static fn (string $s): string => str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $s),
		);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->service = new SearchService(
			$this->boardService,
			$this->cardMapper,
			$this->commentMapper,
			$this->db,
			$this->boardAccess,
		);
	}

	private function board(int $id): Board {
		$board = new Board();
		$board->setId($id);
		return $board;
	}

	private function card(int $id, int $boardId, string $title, string $description = ''): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setTitle($title);
		$card->setDescription($description);
		return $card;
	}

	public function testShortQueryReturnsEmptyWithoutTouchingBoards(): void {
		$this->boardService->expects(self::never())->method('findAllActive');
		$this->cardMapper->expects(self::never())->method('searchInBoards');

		$result = $this->service->search('a', 'alice', null, 25, 0);
		self::assertSame(['query' => 'a', 'total' => 0, 'results' => []], $result);
	}

	public function testBlankQueryReturnsEmpty(): void {
		$this->boardService->expects(self::never())->method('findAllActive');
		$result = $this->service->search('   ', 'alice', null, 25, 0);
		self::assertSame(0, $result['total']);
		self::assertSame([], $result['results']);
	}

	public function testNoReadableBoardsReturnsEmpty(): void {
		$this->boardService->method('findAllActive')->with('alice')->willReturn([]);
		$this->cardMapper->expects(self::never())->method('searchInBoards');
		$this->commentMapper->expects(self::never())->method('searchInBoards');

		$result = $this->service->search('widget', 'alice', null, 25, 0);
		self::assertSame(0, $result['total']);
	}

	public function testRanksCardTitleThenDescriptionThenComment(): void {
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1)]);
		// Source order is most-recent-first; ranking must reorder to title>desc.
		$this->cardMapper->method('searchInBoards')->willReturn([
			$this->card(20, 1, 'Unrelated', 'contains widget in body'), // desc-only → rank 2
			$this->card(21, 1, 'Widget master', 'nope'), // title → rank 3
		]);
		$this->commentMapper->method('searchInBoards')->willReturn([
			['id' => 5, 'cardId' => 22, 'boardId' => 1, 'cardTitle' => 'Card with comment', 'body' => 'a widget mention'],
		]);

		$result = $this->service->search('widget', 'alice', null, 25, 0);

		self::assertSame(3, $result['total']);
		self::assertSame('card', $result['results'][0]['type']);
		self::assertSame(21, $result['results'][0]['cardId']); // title match first
		self::assertSame(20, $result['results'][1]['cardId']); // description match second
		self::assertSame('comment', $result['results'][2]['type']); // comment last
		self::assertSame(22, $result['results'][2]['cardId']);
		self::assertSame(5, $result['results'][2]['commentId']);
	}

	public function testSearchSkipsBoardsTheUserHasArchived(): void {
		// #10126: an archived board is shelved - its cards and comments leave
		// search entirely. The mock mirrors the real BoardService: findAll()
		// STILL carries the archived board (the boards page's Archived tab is
		// built on it), only findAllActive() drops it - so reverting the service
		// to findAll() puts board 7 back in the searched set and this goes red.
		$active = $this->board(1);
		$archived = $this->board(7);
		$archived->setArchived(true);
		$this->boardService->method('findAll')->with('alice')->willReturn([$active, $archived]);
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$active]);
		$roles = [1 => ViewerContext::ROLE_INTERNAL];
		$this->boardAccess->method('rolesFor')->willReturn($roles);

		$this->cardMapper->expects(self::once())
			->method('searchInBoards')
			->with([1], self::anything(), self::anything(), 'alice', $roles)
			->willReturn([$this->card(20, 1, 'Widget master', 'nope')]);
		$this->commentMapper->expects(self::once())
			->method('searchInBoards')
			->with([1], self::anything(), self::anything(), 'alice', $roles)
			->willReturn([]);

		// The identical card on the ACTIVE board still matches - both halves
		// matter, or a filter that dropped everything would pass too.
		$result = $this->service->search('widget', 'alice', null, 25, 0);
		self::assertSame(1, $result['total']);
		self::assertSame(20, $result['results'][0]['cardId']);
	}

	public function testArchivedBoardCannotBeSearchedByExplicitBoardScope(): void {
		// The board-scope parameter is checked against the SAME active set, so
		// naming an archived board explicitly does not reach around the filter.
		$active = $this->board(1);
		$archived = $this->board(7);
		$archived->setArchived(true);
		$this->boardService->method('findAll')->with('alice')->willReturn([$active, $archived]);
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$active]);
		$this->cardMapper->expects(self::never())->method('searchInBoards');
		$this->commentMapper->expects(self::never())->method('searchInBoards');

		$result = $this->service->search('widget', 'alice', 7, 25, 0);
		self::assertSame(0, $result['total']);
	}

	public function testBoardScopeRejectsUnreadableBoard(): void {
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1)]);
		// Requesting board 99 (not readable) must yield no results and never query.
		$this->cardMapper->expects(self::never())->method('searchInBoards');
		$this->commentMapper->expects(self::never())->method('searchInBoards');

		$result = $this->service->search('widget', 'alice', 99, 25, 0);
		self::assertSame(0, $result['total']);
	}

	public function testBoardScopeNarrowsToRequestedReadableBoard(): void {
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1), $this->board(2)]);
		$this->cardMapper->expects(self::once())
			->method('searchInBoards')
			->with([2], self::anything(), self::anything())
			->willReturn([]);
		$this->commentMapper->method('searchInBoards')->with([2], self::anything(), self::anything())->willReturn([]);

		$this->service->search('widget', 'alice', 2, 25, 0);
	}

	public function testSearchesAllReadableBoardsWhenNoBoardScope(): void {
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1), $this->board(7)]);
		// Both sources are scoped by the viewer's uid + per-board roles (#3743).
		$roles = [1 => ViewerContext::ROLE_INTERNAL, 7 => ViewerContext::ROLE_EXTERNAL];
		$this->boardAccess->expects(self::once())->method('rolesFor')->willReturn($roles);
		$this->cardMapper->expects(self::once())
			->method('searchInBoards')
			->with([1, 7], self::anything(), self::anything(), 'alice', $roles)
			->willReturn([]);
		$this->commentMapper->expects(self::once())
			->method('searchInBoards')
			->with([1, 7], self::anything(), self::anything(), 'alice', $roles)
			->willReturn([]);

		$this->service->search('widget', 'alice', null, 25, 0);
	}

	public function testEscapesLikeWildcardsInTerm(): void {
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1)]);
		$this->cardMapper->expects(self::once())
			->method('searchInBoards')
			->willReturnCallback(function (array $ids, string $pattern): array {
				// % and _ must be backslash-escaped so they match literally.
				self::assertStringContainsString('50\\%', $pattern);
				self::assertStringStartsWith('%', $pattern);
				self::assertStringEndsWith('%', $pattern);
				return [];
			});
		$this->commentMapper->method('searchInBoards')->willReturn([]);

		$this->service->search('50%', 'alice', null, 25, 0);
	}

	public function testPaginationSlicesResults(): void {
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1)]);
		$cards = [];
		for ($i = 1; $i <= 5; $i++) {
			$cards[] = $this->card($i, 1, 'Widget ' . $i);
		}
		$this->cardMapper->method('searchInBoards')->willReturn($cards);
		$this->commentMapper->method('searchInBoards')->willReturn([]);

		$result = $this->service->search('widget', 'alice', null, 2, 2);
		self::assertSame(5, $result['total']); // total is the full match count
		self::assertCount(2, $result['results']); // page size
	}

	public function testSnippetIsTruncated(): void {
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$this->board(1)]);
		$long = str_repeat('lorem ipsum ', 40); // > 160 chars
		$this->cardMapper->method('searchInBoards')->willReturn([$this->card(1, 1, 'Widget', $long)]);
		$this->commentMapper->method('searchInBoards')->willReturn([]);

		$result = $this->service->search('widget', 'alice', null, 25, 0);
		$snippet = $result['results'][0]['snippet'];
		self::assertLessThanOrEqual(161, mb_strlen($snippet)); // 160 + ellipsis
		self::assertStringEndsWith('…', $snippet);
	}
}

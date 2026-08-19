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
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
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
	private LabelMapper&MockObject $labelMapper;
	private ViewService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardService = $this->createMock(BoardService::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardSummaryService = $this->createMock(CardSummaryService::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		// Default: no labels on any board unless a test says otherwise.
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->service = new ViewService(
			$this->boardService,
			$this->cardMapper,
			$this->cardSummaryService,
			$this->boardAccess,
			$this->labelMapper,
		);
	}

	private function board(int $id, string $title, ?string $prefix = null): Board {
		$board = new Board();
		$board->setId($id);
		$board->setTitle($title);
		$board->setOwner('alice');
		$board->setPrefix($prefix);
		return $board;
	}

	private function label(int $id, string $title, ?string $color): Label {
		$label = new Label();
		$label->setId($id);
		$label->setTitle($title);
		$label->setColor($color);
		return $label;
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

		$result = $this->service->findMine('alice');

		// The feed is an envelope: uncapped small readable set → capped=false,
		// total = row count, limit = the hard cap.
		self::assertFalse($result['capped']);
		self::assertSame(2, $result['total']);
		self::assertSame(ViewService::MAX_CARDS, $result['limit']);

		$rows = $result['cards'];
		self::assertCount(2, $rows);
		// Rows come back in the stable (boardId, id) order the cap slices on.
		self::assertSame(11, $rows[0]['id']);
		self::assertSame(3, $rows[0]['boardId']);
		self::assertSame('Alpha', $rows[0]['boardTitle']);
		self::assertSame(22, $rows[1]['id']);
		self::assertSame(9, $rows[1]['boardId']);
		self::assertSame('Beta', $rows[1]['boardTitle']);
	}

	/**
	 * Tile parity (#3950): each card carries its board's human-id prefix and the
	 * envelope carries the union of label metadata across the readable boards, so
	 * the client can render card refs (e.g. "KAN-123") and label COLOURS matching
	 * the board tiles - all from this one feed with no extra request.
	 */
	public function testFindMineEnrichesCardsWithBoardPrefixAndUnionsLabels(): void {
		$b3 = $this->board(3, 'Alpha', 'ALP');
		// A board with no explicit prefix falls back to the shared default.
		$b9 = $this->board(9, 'Beta', null);
		$this->boardService->method('findAll')->with('alice')->willReturn([$b3, $b9]);

		$ctx3 = ViewerContext::forMember('alice', 3, ViewerContext::ROLE_INTERNAL, true);
		$ctx9 = ViewerContext::forMember('alice', 9, ViewerContext::ROLE_INTERNAL, true);
		$this->boardAccess->method('contextFor')->willReturnMap([
			[$b3, 'alice', $ctx3],
			[$b9, 'alice', $ctx9],
		]);

		$this->cardMapper->method('findSummariesByBoard')
			->willReturnCallback(fn (int $boardId): array => [$this->summaryCard($boardId === 3 ? 11 : 22, $boardId)]);
		$this->cardSummaryService->method('serialize')
			->willReturnCallback(fn (int $boardId): array => [['id' => $boardId === 3 ? 11 : 22]]);

		// Board 3 has two labels, board 9 one; the envelope unions them.
		$labelMapper = $this->createMock(LabelMapper::class);
		$labelMapper->method('findByBoard')->willReturnCallback(fn (int $boardId): array => $boardId === 3
			? [$this->label(1, 'Bug', '#ff0000'), $this->label(2, 'Chore', null)]
			: [$this->label(5, 'Idea', '#00ff00')]);
		$service = new ViewService($this->boardService, $this->cardMapper, $this->cardSummaryService, $this->boardAccess, $labelMapper);

		$result = $service->findMine('alice');

		$rows = $result['cards'];
		// Explicit prefix carried through; missing prefix falls back to the default.
		self::assertSame('ALP', $rows[0]['boardPrefix']);
		self::assertSame('KAN', $rows[1]['boardPrefix']);

		// Label union across boards, id/title/color preserved for the client lookup.
		$labels = $result['labels'];
		self::assertCount(3, $labels);
		$byId = [];
		foreach ($labels as $l) {
			$byId[$l['id']] = $l;
		}
		self::assertSame('Bug', $byId[1]['title']);
		self::assertSame('#ff0000', $byId[1]['color']);
		self::assertNull($byId[2]['color']);
		self::assertSame('Idea', $byId[5]['title']);
	}

	/**
	 * The scale guard (#3892): the feed is a SINGLE unbounded payload, so it is
	 * hard-capped. Whatever the readable-set size, `cards` never exceeds
	 * MAX_CARDS, `total` reports the true pre-cap count, and `capped` is honest -
	 * the cap is applied AFTER the per-board ACL loop, so it moves no leak boundary.
	 */
	public function testFindMineCapsThePayloadAndReportsTotalWhenReadableSetIsHuge(): void {
		$overCap = ViewService::MAX_CARDS + 250;

		// One readable board whose (viewer-gated) summary set exceeds the cap.
		$b1 = $this->board(1, 'Huge');
		$this->boardService->method('findAll')->with('alice')->willReturn([$b1]);
		$ctx1 = ViewerContext::forMember('alice', 1, ViewerContext::ROLE_INTERNAL, true);
		$this->boardAccess->method('contextFor')->with($b1, 'alice')->willReturn($ctx1);

		// The mapper/serializer still run once per board (ACL gate intact); they
		// return the whole viewer-scoped set, which the cap then slices.
		$this->cardMapper->method('findSummariesByBoard')
			->willReturn(array_map(fn (int $i): Card => $this->summaryCard($i, 1), range(1, $overCap)));
		$this->cardSummaryService->method('serialize')
			->willReturn(array_map(static fn (int $i): array => ['id' => $i], range(1, $overCap)));

		$result = $this->service->findMine('alice');

		self::assertTrue($result['capped']);
		self::assertSame($overCap, $result['total']);
		self::assertSame(ViewService::MAX_CARDS, $result['limit']);
		// The payload is bounded regardless of the readable-set size.
		self::assertCount(ViewService::MAX_CARDS, $result['cards']);
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

		$rows = $this->service->findMine('alice')['cards'];

		$boardIds = array_map(static fn (array $c): int => $c['boardId'], $rows);
		self::assertSame([3], array_values(array_unique($boardIds)));
		// No row from the unreadable board 7 leaked through.
		self::assertNotContains(7, $boardIds);
	}

	public function testFindMineEmptyWhenNoReadableBoards(): void {
		$this->boardService->method('findAll')->with('bob')->willReturn([]);
		$this->boardAccess->expects(self::never())->method('contextFor');
		$this->cardMapper->expects(self::never())->method('findSummariesByBoard');

		$result = $this->service->findMine('bob');
		self::assertSame([], $result['cards']);
		self::assertFalse($result['capped']);
		self::assertSame(0, $result['total']);
	}
}

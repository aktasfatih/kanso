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
use OCA\Kanso\Service\ViewFilter;
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
		$this->labelMapper->method('findByBoards')->willReturn([]);
		$this->service = new ViewService(
			$this->boardService,
			$this->cardMapper,
			$this->cardSummaryService,
			$this->boardAccess,
			$this->labelMapper,
		);
	}

	private function board(int $id, string $title, ?string $prefix = null, string $owner = 'alice'): Board {
		$board = new Board();
		$board->setId($id);
		$board->setTitle($title);
		$board->setOwner($owner);
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

	/**
	 * Wire up a readable board per entry of $rowsByBoard (boardId => summary rows),
	 * so a test can describe the feed it wants in one line.
	 *
	 * The service resolves the whole readable set in ONE batched pass (#10298):
	 * one rolesFor(), one findSummariesByBoards(), one serializeForBoards() - so
	 * the mocks hand back the union of every board's rows, each row carrying the
	 * `boardId` the real serializer copies off the card entity.
	 *
	 * @param array<int, array{title?: string, prefix?: string|null, owner?: string, role?: string, rows: list<array<string, mixed>>}> $rowsByBoard
	 * @return list<Board>
	 */
	private function seedFeed(array $rowsByBoard, string $uid = 'alice'): array {
		$boards = [];
		$roles = [];
		$cards = [];
		$rows = [];
		foreach ($rowsByBoard as $boardId => $spec) {
			$boards[] = $this->board(
				$boardId,
				$spec['title'] ?? ('Board ' . $boardId),
				$spec['prefix'] ?? null,
				$spec['owner'] ?? $uid,
			);
			$roles[$boardId] = $spec['role'] ?? ViewerContext::ROLE_INTERNAL;
			foreach ($spec['rows'] as $row) {
				$cards[] = $this->summaryCard((int)$row['id'], $boardId);
				$rows[] = $row + ['boardId' => $boardId];
			}
		}
		$this->boardService->method('findAllActive')->with($uid)->willReturn($boards);
		$this->boardAccess->method('rolesFor')->willReturn($roles);
		$this->cardMapper->method('findSummariesByBoards')->willReturn($cards);
		$this->cardSummaryService->method('serializeForBoards')->willReturn($rows);
		return $boards;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<int>
	 */
	private function idsOf(array $result): array {
		return array_map(static fn (array $c): int => (int)$c['id'], $result['cards']);
	}

	public function testFindMineReturnsCardsFromEveryReadableBoardTaggedWithBoardIdentity(): void {
		$this->seedFeed([
			3 => ['title' => 'Alpha', 'rows' => [['id' => 11]]],
			9 => ['title' => 'Beta', 'role' => ViewerContext::ROLE_EXTERNAL, 'rows' => [['id' => 22]]],
		]);

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

	// ── The batching contract (#10298) ───────────────────────────────────────────

	/**
	 * THE performance contract this feed used to be the sole violator of: the
	 * cross-board read is a FIXED number of queries, not one enrichment pass per
	 * readable board. {@see BoardAccess} says it in its own docblock - "batch via
	 * rolesFor() … never per-board queries" - and every sibling feed (My Cards,
	 * Inbox, Search, My Steps, Reviews) obeys it.
	 *
	 * Pinned by CALL COUNT over a 3-board set: one ACL resolve, one summary
	 * query, one enrichment pass, one label fetch - and NOT ONE per-board
	 * contextFor(). Re-introducing the loop makes every one of these go red.
	 */
	public function testFindMineResolvesTheWholeBoardSetInOneBatchedPass(): void {
		$b3 = $this->board(3, 'Alpha');
		$b9 = $this->board(9, 'Beta');
		$b12 = $this->board(12, 'Gamma');
		$boards = [$b3, $b9, $b12];
		$roles = [
			3 => ViewerContext::ROLE_INTERNAL,
			9 => ViewerContext::ROLE_EXTERNAL,
			12 => ViewerContext::ROLE_INTERNAL,
		];
		$this->boardService->method('findAllActive')->with('alice')->willReturn($boards);

		$this->boardAccess->expects(self::once())
			->method('rolesFor')->with($boards, 'alice')->willReturn($roles);
		// The per-board resolver is NOT reachable from this feed any more.
		$this->boardAccess->expects(self::never())->method('contextFor');

		$this->cardMapper->expects(self::once())
			->method('findSummariesByBoards')
			->with([3, 9, 12], 'alice', $roles)
			->willReturn([$this->summaryCard(11, 3), $this->summaryCard(22, 9), $this->summaryCard(33, 12)]);
		$this->cardSummaryService->expects(self::once())
			->method('serializeForBoards')
			->with([3, 9, 12], self::anything(), 'alice', $roles)
			->willReturn([
				['id' => 11, 'boardId' => 3],
				['id' => 22, 'boardId' => 9],
				['id' => 33, 'boardId' => 12],
			]);

		$labelMapper = $this->createMock(LabelMapper::class);
		$labelMapper->expects(self::once())->method('findByBoards')->with([3, 9, 12])->willReturn([]);
		$labelMapper->expects(self::never())->method('findByBoard');
		$service = new ViewService($this->boardService, $this->cardMapper, $this->cardSummaryService, $this->boardAccess, $labelMapper);

		self::assertSame([11, 22, 33], $this->idsOf($service->findMine('alice')));
	}

	/**
	 * Batching must not flatten the ROLE. A viewer is routinely internal on one
	 * board and external on another (provider side here, client side there), and
	 * the #3743 masking is per board - so the map handed to the card query has to
	 * carry each board's own side, verbatim from BoardAccess. A single "the
	 * viewer's role" would silently widen the client-side boards.
	 */
	public function testFindMineCarriesEachBoardsOwnRoleForAMixedRoleViewer(): void {
		$owned = $this->board(3, 'Owned', null, 'alice');
		$provider = $this->board(9, 'Provider side', null, 'bob');
		$client = $this->board(12, 'Client side', null, 'bob');
		$roles = [
			3 => ViewerContext::ROLE_INTERNAL,
			9 => ViewerContext::ROLE_INTERNAL,
			12 => ViewerContext::ROLE_EXTERNAL,
		];
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$owned, $provider, $client]);
		$this->boardAccess->method('rolesFor')->with([$owned, $provider, $client], 'alice')->willReturn($roles);

		$seen = [];
		$this->cardMapper->method('findSummariesByBoards')
			->willReturnCallback(function (array $boardIds, string $uid, array $rolesByBoard) use (&$seen): array {
				$seen[] = [$boardIds, $uid, $rolesByBoard];
				return [];
			});
		$this->cardSummaryService->method('serializeForBoards')
			->willReturnCallback(function (array $boardIds, array $cards, string $uid, array $rolesByBoard) use (&$seen): array {
				$seen[] = [$boardIds, $uid, $rolesByBoard];
				return [];
			});

		$this->service->findMine('alice');

		// Both viewer-scoped reads got the SAME per-board role map - internal on
		// the two provider-side boards, external on the client-side one.
		self::assertCount(2, $seen);
		foreach ($seen as $call) {
			self::assertSame([3, 9, 12], $call[0]);
			self::assertSame('alice', $call[1]);
			self::assertSame($roles, $call[2]);
			self::assertSame(ViewerContext::ROLE_EXTERNAL, $call[2][12]);
		}
	}

	/**
	 * Tile parity (#3950): each card carries its board's human-id prefix and the
	 * envelope carries the union of label metadata across the readable boards, so
	 * the client can render card refs (e.g. "KAN-123") and label COLOURS matching
	 * the board tiles - all from this one feed with no extra request.
	 *
	 * The union is now ONE query for the whole board set, walked in board order -
	 * so the emitted sequence is exactly what the old per-board loop produced.
	 */
	public function testFindMineEnrichesCardsWithBoardPrefixAndUnionsLabels(): void {
		$this->seedFeed([
			3 => ['title' => 'Alpha', 'prefix' => 'ALP', 'rows' => [['id' => 11]]],
			// A board with no explicit prefix falls back to the shared default.
			9 => ['title' => 'Beta', 'rows' => [['id' => 22]]],
		]);

		// Board 3 has one label, board 9 two; the envelope unions them.
		//
		// The label ids are deliberately INVERTED against the board order - the
		// FIRST board owns the HIGHEST id - and the map comes back keyed in the
		// order the real `id ASC` query groups it (board 9 first, because it
		// owns the lower ids), NOT in board order. So the expected sequence
		// below can only come from walking $boardIds; iterating the batched map
		// itself would give [1, 2, 5] and fail.
		$labelMapper = $this->createMock(LabelMapper::class);
		$labelMapper->method('findByBoards')->with([3, 9])->willReturn([
			9 => [$this->label(1, 'Bug', '#ff0000'), $this->label(2, 'Chore', null)],
			3 => [$this->label(5, 'Idea', '#00ff00')],
		]);
		$service = new ViewService($this->boardService, $this->cardMapper, $this->cardSummaryService, $this->boardAccess, $labelMapper);

		$result = $service->findMine('alice');

		$rows = $result['cards'];
		// Explicit prefix carried through; missing prefix falls back to the default.
		self::assertSame('ALP', $rows[0]['boardPrefix']);
		self::assertSame('KAN', $rows[1]['boardPrefix']);

		// Label union across boards, id/title/color preserved for the client lookup,
		// in BOARD order then per-board creation order.
		$labels = $result['labels'];
		self::assertCount(3, $labels);
		self::assertSame([5, 1, 2], array_column($labels, 'id'));
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
	 * hard-capped. Whatever the set size, `cards` never exceeds MAX_CARDS, `total`
	 * reports the true pre-cap count, and `capped` is honest - the cap is applied
	 * AFTER the ACL + #3743 masking, so it moves no leak boundary.
	 *
	 * Post-filter semantics (#9862): `total` counts MATCHING rows, so with no
	 * filter - as here - it is still the whole readable-set count. The filtered
	 * counterparts live in testFindMineAppliesTheFilterBeforeTheCap() and
	 * testFindMineReportsTheMatchingTotalNotTheReadableTotal() below.
	 */
	public function testFindMineCapsThePayloadAndReportsTotalWhenReadableSetIsHuge(): void {
		$overCap = ViewService::MAX_CARDS + 250;
		$this->seedFeed([1 => ['title' => 'Huge', 'rows' => array_map(
			static fn (int $i): array => ['id' => $i],
			range(1, $overCap),
		)]]);

		$result = $this->service->findMine('alice');

		self::assertTrue($result['capped']);
		self::assertSame($overCap, $result['total']);
		self::assertSame(ViewService::MAX_CARDS, $result['limit']);
		// The payload is bounded regardless of the readable-set size.
		self::assertCount(ViewService::MAX_CARDS, $result['cards']);
	}

	/**
	 * The ACL boundary (#3815, REQUIRED leak-denial): a View run by user A must
	 * NEVER surface a card from a board A cannot read. findAllActive() is the
	 * single readable-set gate - the batched query is handed EXACTLY that board
	 * set and exactly those roles, so an unreadable board is neither queried nor
	 * reachable through the role map (a board absent from the map contributes no
	 * internal branch at all).
	 */
	public function testFindMineNeverQueriesABoardOutsideTheReadableSet(): void {
		// alice can read board 3 only; board 7 (which she cannot read) exists in
		// the system but is absent from findAllActive()'s readable set.
		$b3 = $this->board(3, 'Readable');
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$b3]);
		$this->boardAccess->method('rolesFor')->with([$b3], 'alice')
			->willReturn([3 => ViewerContext::ROLE_INTERNAL]);

		// The query is asked ONLY for board 3, with board 3's role - never for
		// the unreadable board 7 under any role.
		$this->cardMapper->expects(self::once())
			->method('findSummariesByBoards')
			->with([3], 'alice', [3 => ViewerContext::ROLE_INTERNAL])
			->willReturn([$this->summaryCard(11, 3)]);
		$this->cardSummaryService->expects(self::once())
			->method('serializeForBoards')
			->with([3], self::anything(), 'alice', [3 => ViewerContext::ROLE_INTERNAL])
			->willReturn([['id' => 11, 'boardId' => 3, 'title' => 'Only mine']]);

		$rows = $this->service->findMine('alice')['cards'];

		$boardIds = array_map(static fn (array $c): int => $c['boardId'], $rows);
		self::assertSame([3], array_values(array_unique($boardIds)));
		// No row from the unreadable board 7 leaked through.
		self::assertNotContains(7, $boardIds);
	}

	/**
	 * Defence in depth on the same boundary: even if a row for an unreadable
	 * board ever reached the assembly loop (it cannot - the query is restricted
	 * to the readable set), it is DROPPED rather than shipped with a blank board
	 * identity. The masking fails closed, not open.
	 */
	public function testFindMineDropsARowWhoseBoardIsNotInTheReadableSet(): void {
		$b3 = $this->board(3, 'Readable');
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$b3]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);
		$this->cardMapper->method('findSummariesByBoards')->willReturn([$this->summaryCard(11, 3)]);
		$this->cardSummaryService->method('serializeForBoards')->willReturn([
			['id' => 11, 'boardId' => 3, 'owner' => 'alice'],
			// A row claiming a board outside the readable set.
			['id' => 99, 'boardId' => 7, 'owner' => 'mallory', 'assigneeIds' => ['mallory']],
		]);

		$result = $this->service->findMine('alice');

		self::assertSame([11], $this->idsOf($result));
		self::assertSame(1, $result['total']);
		// …and its people never enter the facet vocabulary either.
		self::assertSame(['alice'], $result['participants']);
	}

	/**
	 * Due sort (#9860). Semantics match the board's display sort: present values
	 * flip with the direction, and a card with NO due date sorts last in BOTH
	 * directions rather than leading the descending list.
	 */
	public function testFindMineSortsByDueDateWithMissingValuesLastInBothDirections(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'duedate' => '2030-01-03T09:00:00+00:00'],
			['id' => 2, 'duedate' => null],
			['id' => 3, 'duedate' => '2030-01-01T09:00:00+00:00'],
			['id' => 4, 'duedate' => '2030-01-02T09:00:00+00:00'],
		]]]);

		self::assertSame([3, 4, 1, 2], $this->idsOf($this->service->findMine('alice', 'due', 'asc')));
		self::assertSame([1, 4, 3, 2], $this->idsOf($this->service->findMine('alice', 'due', 'desc')));
	}

	/**
	 * Priority sort: 0 ("no priority") is a real LOW value, not a missing one -
	 * same as the board - so it leads ascending and trails descending.
	 */
	public function testFindMineSortsByPriorityTreatingZeroAsARealLowValue(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'priority' => 0],
			['id' => 2, 'priority' => 3],
			['id' => 3, 'priority' => 1],
		]]]);

		self::assertSame([2, 3, 1], $this->idsOf($this->service->findMine('alice', 'priority', 'desc')));
		self::assertSame([1, 3, 2], $this->idsOf($this->service->findMine('alice', 'priority', 'asc')));
	}

	/** Title sort is case-insensitive (A→Z), so "Apple" and "apricot" sit together. */
	public function testFindMineSortsByTitleCaseInsensitively(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'title' => 'banana'],
			['id' => 2, 'title' => 'Apple'],
			['id' => 3, 'title' => 'cherry'],
		]]]);

		self::assertSame([2, 1, 3], $this->idsOf($this->service->findMine('alice', 'title', 'asc')));
		self::assertSame([3, 1, 2], $this->idsOf($this->service->findMine('alice', 'title', 'desc')));
	}

	/**
	 * Board sort orders by board TITLE, so it genuinely replaces the old fixed
	 * (boardId, id) order rather than dressing it up: board 9 "Alpha" leads board
	 * 3 "Zulu" even though its id is higher.
	 */
	public function testFindMineSortsByBoardTitleNotBoardId(): void {
		$this->seedFeed([
			3 => ['title' => 'Zulu', 'rows' => [['id' => 11]]],
			9 => ['title' => 'Alpha', 'rows' => [['id' => 22]]],
		]);

		self::assertSame([22, 11], $this->idsOf($this->service->findMine('alice', 'board', 'asc')));
		self::assertSame([11, 22], $this->idsOf($this->service->findMine('alice', 'board', 'desc')));
	}

	/**
	 * The whole point of sorting SERVER-side (#9860): the sort runs over the whole
	 * readable set BEFORE the cap slices it, so a sorted View starts at the true
	 * first row - not at the first row of the arbitrary default-ordered window.
	 * Here the oldest card is the LAST row in default order, so it would be cut by
	 * the cap if the slice happened first.
	 */
	public function testFindMineAppliesTheSortBeforeTheCap(): void {
		$overCap = ViewService::MAX_CARDS + 250;
		// createdAt descends with the id: the oldest card is the very last row of
		// the default (boardId, id) order, i.e. outside the capped window.
		$rows = array_map(
			static fn (int $i): array => ['id' => $i, 'createdAt' => $overCap - $i + 1],
			range(1, $overCap),
		);
		$this->seedFeed([1 => ['rows' => $rows]]);

		$result = $this->service->findMine('alice', 'created', 'asc');

		self::assertTrue($result['capped']);
		self::assertSame($overCap, $result['total']);
		self::assertCount(ViewService::MAX_CARDS, $result['cards']);
		// The oldest card leads the payload, so the cap sliced the SORTED set.
		self::assertSame($overCap, $result['cards'][0]['id']);
		self::assertSame($overCap - 1, $result['cards'][1]['id']);
	}

	/**
	 * A sort mode this version doesn't know - an older/newer client, or one of the
	 * deliberately excluded board-only modes ('manual', 'estimate') - is IGNORED and
	 * defaulted to the stable (boardId, id) order. Never an error: a saved View must
	 * not be able to hard-fail the feed.
	 */
	public function testFindMineIgnoresAnUnknownSortModeAndKeepsTheStableOrder(): void {
		$this->seedFeed([
			3 => ['rows' => [['id' => 22, 'title' => 'zzz'], ['id' => 11, 'title' => 'aaa']]],
			9 => ['rows' => [['id' => 5, 'title' => 'mmm']]],
		]);

		self::assertSame([11, 22, 5], $this->idsOf($this->service->findMine('alice', 'manual', 'asc')));
		self::assertSame([11, 22, 5], $this->idsOf($this->service->findMine('alice', 'estimate', 'desc')));
		self::assertSame([11, 22, 5], $this->idsOf($this->service->findMine('alice', 'nonsense', 'sideways')));
		// And the untouched default is the same order the feed always had.
		self::assertSame([11, 22, 5], $this->idsOf($this->service->findMine('alice')));
	}

	/**
	 * Sorting must run strictly AFTER the ACL / #3743 masking, never as a shortcut
	 * around it: with a sort active the readable set is still the ONLY thing
	 * queried, so no unreadable board's card can be sorted into the feed.
	 */
	public function testFindMineWithASortStillNeverQueriesABoardOutsideTheReadableSet(): void {
		$b3 = $this->board(3, 'Readable');
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$b3]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);

		$this->cardMapper->expects(self::once())
			->method('findSummariesByBoards')
			->with([3], 'alice', [3 => ViewerContext::ROLE_INTERNAL])
			->willReturn([$this->summaryCard(11, 3)]);
		$this->cardSummaryService->method('serializeForBoards')
			->willReturn([['id' => 11, 'boardId' => 3, 'title' => 'Only mine']]);

		$rows = $this->service->findMine('alice', 'title', 'asc')['cards'];

		$boardIds = array_map(static fn (array $c): int => (int)$c['boardId'], $rows);
		self::assertSame([3], array_values(array_unique($boardIds)));
		self::assertNotContains(7, $boardIds);
	}

	// ── The View filter (#9862) ──────────────────────────────────────────────────

	/**
	 * THE bug this filter exists to fix. The feed is hard-capped, and the filter
	 * used to run only in the browser - so on an account with more readable cards
	 * than the cap, a narrow filter searched ONLY the first MAX_CARDS rows of the
	 * sorted order and silently missed every match past them.
	 *
	 * Asserting that at >5000 rows through the UI is impractical, so it is pinned
	 * here instead, at the exact seam that was wrong: the readable set is larger
	 * than the cap and the ONLY matching cards sit at the very END of it, well
	 * outside the capped window. They come back, which is only possible if the
	 * filter ran before the slice.
	 */
	public function testFindMineAppliesTheFilterBeforeTheCap(): void {
		$overCap = ViewService::MAX_CARDS + 250;
		// Every row is owned by 'alice' EXCEPT the last three, which are the only
		// ones the filter wants - i.e. they are outside the first MAX_CARDS rows of
		// the default (boardId, id) order the cap slices on.
		$needles = [$overCap - 2, $overCap - 1, $overCap];
		$rows = array_map(
			static fn (int $i): array => [
				'id' => $i,
				'owner' => $i > $overCap - 3 ? 'zoe' : 'alice',
			],
			range(1, $overCap),
		);
		$this->seedFeed([1 => ['rows' => $rows]]);

		$result = $this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery(['fo' => 'zoe']));

		// Pre-fix this returned an empty list: the cap had already thrown these rows
		// away before any filter could see them.
		self::assertSame($needles, $this->idsOf($result));
		self::assertFalse($result['capped'], 'the matching set fits well inside the cap');
		self::assertSame(3, $result['total'], 'total counts MATCHING cards, not readable ones');
	}

	/**
	 * `total` / `capped` describe the MATCHING set, so the "showing the first N of
	 * M" banner is honest about what refining the filter would actually do. Here
	 * the matching set is itself larger than the cap.
	 */
	public function testFindMineReportsTheMatchingTotalNotTheReadableTotal(): void {
		$overCap = ViewService::MAX_CARDS + 250;
		// Half the readable set is done; the filter keeps only the open ones, which
		// still overflow the cap.
		$rows = array_map(
			static fn (int $i): array => ['id' => $i, 'doneAt' => $i % 2 === 0 ? 1700000000 : 0],
			range(1, 2 * $overCap),
		);
		$this->seedFeed([1 => ['rows' => $rows]]);

		$result = $this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery(['fs' => 'open']));

		self::assertTrue($result['capped']);
		self::assertSame($overCap, $result['total'], 'the matching count, not the 2x readable count');
		self::assertCount(ViewService::MAX_CARDS, $result['cards']);
		// …and the window it kept is the head of the MATCHING set (odd ids only).
		self::assertSame([1, 3, 5], array_slice($this->idsOf($result), 0, 3));
	}

	/**
	 * The facet-collapse guard. `participants` is accumulated BEFORE the filter, so
	 * the client's assignee/owner facets keep offering everyone however narrow the
	 * filter gets - including at ZERO matches, where a row-derived facet would
	 * vanish outright and leave no way to add a second person back.
	 */
	public function testFindMineShipsTheWholeParticipantVocabularyEvenAtZeroMatches(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'owner' => 'alice', 'assigneeIds' => ['alice']],
			['id' => 2, 'owner' => 'bob', 'assigneeIds' => ['bob', 'carol']],
		]]]);

		// A filter nobody matches.
		$result = $this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery(['fo' => 'nobody']));

		self::assertSame([], $result['cards']);
		self::assertSame(0, $result['total']);
		// The facet vocabulary survives the wipe-out, sorted and de-duplicated.
		self::assertSame(['alice', 'bob', 'carol'], $result['participants']);
	}

	/** Unfiltered, the same vocabulary is the union across every readable board. */
	public function testFindMineUnionsParticipantsAcrossReadableBoards(): void {
		$this->seedFeed([
			3 => ['rows' => [['id' => 11, 'owner' => 'alice', 'assigneeIds' => ['dave']]]],
			9 => ['rows' => [['id' => 22, 'owner' => 'bob', 'assigneeIds' => ['alice']]]],
		]);

		self::assertSame(['alice', 'bob', 'dave'], $this->service->findMine('alice')['participants']);
	}

	/**
	 * A NUMERIC uid must survive as a STRING. `participants` is accumulated as an
	 * array KEY set, and PHP silently coerces a canonical decimal string key to int -
	 * so a uid like '12345' (routine wherever accounts are provisioned from LDAP
	 * employee numbers) would be stored as int(12345), come back from array_keys() as
	 * a number, and ship in the envelope as a JSON number. The client drops non-string
	 * entries, so that account would disappear from the assignee/owner facet with no
	 * way to add them back - and at zero matches the facet hides entirely. That is
	 * precisely the facet self-narrowing this vocabulary exists to prevent, which is
	 * why the type is pinned here and not just the membership.
	 */
	public function testFindMineShipsNumericUidsAsStringsNotNumbers(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'owner' => '12345', 'assigneeIds' => ['67890']],
			['id' => 2, 'owner' => 'alice', 'assigneeIds' => ['alice']],
		]]]);

		$participants = $this->service->findMine('alice')['participants'];

		// assertSame is strict: int(12345) would NOT satisfy '12345'.
		self::assertSame(['12345', '67890', 'alice'], $participants);
		foreach ($participants as $uid) {
			self::assertIsString($uid, 'every participant uid must ship as a string');
		}
	}

	/**
	 * The ACL boundary again, this time with a filter active (REQUIRED leak-denial).
	 * Filtering must never become a shortcut around the permission masking: it runs
	 * strictly AFTER it, over rows the viewer may already see, so it can only ever
	 * REMOVE rows - never surface one from a board outside the readable set,
	 * however the filter is spelled.
	 */
	public function testFindMineWithAFilterStillNeverQueriesABoardOutsideTheReadableSet(): void {
		// alice can read board 3 only. Board 7 exists but is absent from findAllActive().
		$b3 = $this->board(3, 'Readable');
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$b3]);
		$this->boardAccess->method('rolesFor')->willReturn([3 => ViewerContext::ROLE_INTERNAL]);

		// Asked ONLY for board 3, with board 3's role - the filter does not widen,
		// re-run or bypass the query.
		$this->cardMapper->expects(self::once())
			->method('findSummariesByBoards')
			->with([3], 'alice', [3 => ViewerContext::ROLE_INTERNAL])
			->willReturn([$this->summaryCard(11, 3)]);
		$this->cardSummaryService->method('serializeForBoards')
			->willReturn([['id' => 11, 'boardId' => 3, 'owner' => 'mallory', 'assigneeIds' => ['mallory']]]);

		// A filter deliberately shaped to describe the unreadable board's card.
		$result = $this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery([
			'fo' => 'mallory',
			'fa' => 'mallory',
		]));

		$boardIds = array_map(static fn (array $c): int => (int)$c['boardId'], $result['cards']);
		self::assertSame([3], array_values(array_unique($boardIds)));
		self::assertNotContains(7, $boardIds, 'a filter must never surface a card from an unreadable board');
		// The vocabulary is scoped to the readable set too - it cannot become a
		// side channel for uids seen only on boards alice cannot read.
		self::assertSame(['mallory'], $result['participants']);
	}

	/**
	 * An empty filter (or one whose every value this version doesn't recognise) is a
	 * no-op: the feed is exactly what it was before the filter shipped. This is the
	 * tolerance half of the contract - a filter must never be able to blank a View.
	 */
	public function testFindMineTreatsAnEmptyOrUnrecognisedFilterAsNoConstraint(): void {
		$this->seedFeed([1 => ['rows' => [['id' => 1], ['id' => 2], ['id' => 3]]]]);

		self::assertSame([1, 2, 3], $this->idsOf($this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery([]))));
		// An unknown key and an unrecognised value in a known dimension are both
		// ignored rather than dropping every row.
		self::assertSame([1, 2, 3], $this->idsOf($this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery(['fzz' => 'x', 'fd' => 'someday']))));
		// …and passing no filter at all is the same thing.
		self::assertSame([1, 2, 3], $this->idsOf($this->service->findMine('alice')));
	}

	/**
	 * The filter runs BEFORE the sort, so the sort orders the matching set. Both
	 * still run after the ACL masking.
	 *
	 * The rows are deliberately seeded so the sorted answer REVERSES fixture order:
	 * alice owns 1:'cherry' and 3:'apple', so an unsorted (or mis-sorted) pass
	 * returns [1, 3] and only a real `title asc` returns [3, 1]. Sorting by title
	 * rather than id also keeps this honest if the seed order ever changes.
	 */
	public function testFindMineSortsTheFilteredSet(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'title' => 'cherry', 'owner' => 'alice'],
			['id' => 2, 'title' => 'banana', 'owner' => 'bob'],
			['id' => 3, 'title' => 'apple', 'owner' => 'alice'],
		]]]);

		$result = $this->service->findMine('alice', 'title', 'asc', ViewFilter::fromQuery(['fo' => 'alice']));

		self::assertSame([3, 1], $this->idsOf($result));
		self::assertSame(2, $result['total']);
	}

	// ── Archived cards are excluded by default (#10052) ──────────────────────────

	/**
	 * A View is a list of live work, so an archived card must not appear in it -
	 * and must not be COUNTED either. The count half is the part a client-only fix
	 * cannot give you: archived rows would still eat the MAX_CARDS budget and
	 * inflate `total`/`capped`, so the "showing the first N of M" banner would lie
	 * about cards the user can never see in the View.
	 *
	 * The exclusion deliberately does NOT live in CardMapper::findSummariesByBoards()
	 * - its board-scoped twin backs BoardController::show(), which ships archived
	 * rows on purpose (the archived-cards page and its counter are built on them).
	 */
	public function testFindMineExcludesArchivedCardsFromBothTheRowsAndTheTotal(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'title' => 'live'],
			['id' => 2, 'title' => 'archived', 'archived' => true],
			['id' => 3, 'title' => 'also live', 'archived' => false],
		]]]);

		$result = $this->service->findMine('alice');

		self::assertSame([1, 3], $this->idsOf($result));
		self::assertSame(2, $result['total'], 'an archived card must not be counted either');
		self::assertFalse($result['capped']);
	}

	/**
	 * The opt-in half. `archived: include` widens the feed back to everything;
	 * `archived: only` narrows it to the archived rows. Both are spelled with the
	 * existing short-key wire format (`far`), no new machinery.
	 */
	public function testFindMineOptsArchivedCardsBackInThroughTheArchivedFacet(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'title' => 'live'],
			['id' => 2, 'title' => 'archived', 'archived' => true],
		]]]);

		$include = $this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery(['far' => 'include']));
		self::assertSame([1, 2], $this->idsOf($include));
		self::assertSame(2, $include['total']);

		$only = $this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery(['far' => 'only']));
		self::assertSame([2], $this->idsOf($only));
		self::assertSame(1, $only['total']);
	}

	/**
	 * The exclusion runs BEFORE the cap, like the filter - otherwise a readable set
	 * padded with archived rows would push the live ones out of the capped window
	 * and the View would come back with nothing to show.
	 *
	 * Here every row inside the first MAX_CARDS of the default order is archived,
	 * so a fix applied after the slice (or only in the browser) returns an empty
	 * list; only excluding first surfaces the live tail.
	 */
	public function testFindMineExcludesArchivedRowsBeforeTheCap(): void {
		$overCap = ViewService::MAX_CARDS + 250;
		$live = [$overCap - 2, $overCap - 1, $overCap];
		$rows = array_map(
			static fn (int $i): array => ['id' => $i, 'archived' => $i <= $overCap - 3],
			range(1, $overCap),
		);
		$this->seedFeed([1 => ['rows' => $rows]]);

		$result = $this->service->findMine('alice');

		self::assertSame($live, $this->idsOf($result));
		self::assertFalse($result['capped'], 'the live set fits well inside the cap');
		self::assertSame(3, $result['total'], 'archived rows must not inflate the cap budget');
	}

	/**
	 * The facet vocabulary follows the rows: someone who only ever appears on
	 * archived cards is not offered by the assignee/owner facets, because filtering
	 * to them could not match a single visible row. Opting archived back in brings
	 * them back.
	 */
	public function testFindMineKeepsArchivedOnlyParticipantsOutOfTheVocabulary(): void {
		$this->seedFeed([1 => ['rows' => [
			['id' => 1, 'owner' => 'alice', 'assigneeIds' => ['alice']],
			['id' => 2, 'owner' => 'zoe', 'assigneeIds' => ['zoe'], 'archived' => true],
		]]]);

		self::assertSame(['alice'], $this->service->findMine('alice')['participants']);
		self::assertSame(
			['alice', 'zoe'],
			$this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery(['far' => 'include']))['participants'],
		);
	}

	/**
	 * #10126: an ARCHIVED BOARD is out of the View entirely - including its live,
	 * never-archived cards - and UNCONDITIONALLY: the archived-CARDS facet
	 * ('include' / 'only') is about archived cards on boards that are still
	 * ACTIVE, so it can never opt an archived board's rows back in.
	 *
	 * The wiring mirrors the real BoardService: findAll() STILL carries the
	 * archived board (the boards page's Archived tab is built on it), only
	 * findAllActive() drops it - and the mappers WOULD hand back a row for board
	 * 9 if it were ever queried. So reverting the service to findAll() makes card
	 * 22 reappear and this goes red.
	 */
	public function testFindMineExcludesArchivedBoardsEvenUnderTheArchivedFacet(): void {
		$active = $this->board(3, 'Alpha');
		$shelved = $this->board(9, 'Shelved');
		$shelved->setArchived(true);
		$this->boardService->method('findAll')->with('alice')->willReturn([$active, $shelved]);
		$this->boardService->method('findAllActive')->with('alice')->willReturn([$active]);
		// The role resolver answers for whatever board set it is handed - so a
		// service reverted to findAll() would still resolve board 9 and query it.
		$this->boardAccess->method('rolesFor')->willReturnCallback(
			static fn (array $boards): array => array_combine(
				array_map(static fn (Board $b): int => (int)$b->getId(), $boards),
				array_fill(0, count($boards), ViewerContext::ROLE_INTERNAL),
			),
		);
		$this->cardMapper->method('findSummariesByBoards')->willReturnCallback(
			fn (array $boardIds): array => array_map(
				fn (int $boardId): Card => $this->summaryCard($boardId === 3 ? 11 : 22, $boardId),
				$boardIds,
			),
		);
		// The archived board's card is NOT itself archived - exactly the reported
		// case: a shelved board whose cards were never archived one by one.
		$this->cardSummaryService->method('serializeForBoards')->willReturnCallback(
			static fn (array $boardIds): array => array_map(
				static fn (int $boardId): array => [
					'id' => $boardId === 3 ? 11 : 22,
					'boardId' => $boardId,
					'archived' => false,
				],
				$boardIds,
			),
		);

		foreach (['default' => [], 'include' => ['far' => 'include'], 'only' => ['far' => 'only']] as $label => $query) {
			$result = $this->service->findMine('alice', 'default', 'asc', ViewFilter::fromQuery($query));
			self::assertNotContains(22, $this->idsOf($result), 'archived board leaked with archived=' . $label);
			self::assertNotContains(9, array_column($result['cards'], 'boardId'), 'archived board leaked with archived=' . $label);
		}

		// The ACTIVE board's card is still in the feed - both halves matter, or a
		// filter that dropped everything would pass too.
		$default = $this->service->findMine('alice');
		self::assertSame([11], $this->idsOf($default));
		self::assertSame(1, $default['total']);
	}

	public function testFindMineEmptyWhenNoReadableBoards(): void {
		$this->boardService->method('findAllActive')->with('bob')->willReturn([]);
		// Nothing readable means nothing resolved and nothing queried - not even
		// an empty-set round trip.
		$this->boardAccess->expects(self::never())->method('rolesFor');
		$this->boardAccess->expects(self::never())->method('contextFor');
		$this->cardMapper->expects(self::never())->method('findSummariesByBoards');
		$this->cardSummaryService->expects(self::never())->method('serializeForBoards');

		$result = $this->service->findMine('bob');
		self::assertSame([], $result['cards']);
		self::assertSame([], $result['labels']);
		self::assertSame([], $result['participants']);
		self::assertFalse($result['capped']);
		self::assertSame(0, $result['total']);
		self::assertSame(ViewService::MAX_CARDS, $result['limit']);
	}
}

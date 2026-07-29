<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Service\StatsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatsServiceTest extends TestCase {
	private BoardMapper&MockObject $boardMapper;
	private CardMapper&MockObject $cardMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private CommentMapper&MockObject $commentMapper;
	private StatsService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->service = new StatsService(
			$this->boardMapper,
			$this->cardMapper,
			$this->cardAssigneeMapper,
			$this->cardLabelMapper,
			$this->checklistItemMapper,
			$this->commentMapper,
		);
	}

	private function board(string $scale): Board {
		$b = new Board();
		$b->setId(1);
		$b->setEstimateScale($scale);
		return $b;
	}

	/**
	 * @param list<array{createdAt: int, doneAt: int, estimate: ?string}> $completions
	 */
	private function stubCommonAggregates(array $completions = []): void {
		$this->cardMapper->method('countByStack')->willReturn([['stackId' => 42, 'count' => 3]]);
		$this->cardMapper->method('countByPriority')->willReturn([['priority' => 4, 'count' => 1]]);
		$this->cardAssigneeMapper->method('countByAssigneeForBoard')->willReturn([['uid' => 'alice', 'count' => 2]]);
		$this->cardLabelMapper->method('countByLabelForBoard')->willReturn([['labelId' => 9, 'count' => 5]]);
		// doneTimeline is stubbed per-test where the throughput timeline matters;
		// createdTimeline defaults to empty; doneCycleTimes (velocity + cycle
		// time source) defaults to empty and is passed in where those matter.
		$this->cardMapper->method('createdTimeline')->willReturn([]);
		$this->cardMapper->method('doneCycleTimes')->willReturn($completions);
		$this->cardMapper->method('agingCount')->willReturn(4);
		$this->cardMapper->method('overdueCount')->willReturn(2);
		$this->checklistItemMapper->method('progressByBoard')->willReturn([
			5 => ['total' => 3, 'done' => 1],
			7 => ['total' => 2, 'done' => 2],
		]);
		$this->commentMapper->method('countRecentForBoard')->willReturn(11);
	}

	public function testComposesDtoAndSumsChecklistTotals(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('none'));
		$this->stubCommonAggregates();
		$this->cardMapper->method('doneTimeline')->willReturn([]);

		$dto = $this->service->boardStats(1);

		self::assertSame([['stackId' => 42, 'count' => 3]], $dto['byStack']);
		self::assertSame([['uid' => 'alice', 'count' => 2]], $dto['byAssignee']);
		self::assertSame(14, $dto['aging']['days']);
		self::assertSame(4, $dto['aging']['count']);
		self::assertSame(2, $dto['overdue']);
		self::assertSame(11, $dto['commentActivity']);
		// Checklist totals summed across the per-card progress map.
		self::assertSame(['total' => 5, 'done' => 3], $dto['checklist']);
	}

	public function testEstimatePanelsNullWhenScaleNone(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('none'));
		$this->stubCommonAggregates();
		$this->cardMapper->method('doneTimeline')->willReturn([]);
		// No estimate queries should run for a 'none' board.
		$this->cardMapper->expects(self::never())->method('estimateByStack');
		$this->cardAssigneeMapper->expects(self::never())->method('estimateByAssigneeForBoard');

		$dto = $this->service->boardStats(1);

		self::assertNull($dto['estimateByStack']);
		self::assertNull($dto['estimateByAssignee']);
	}

	public function testEstimatePanelsSumNumericScaleAndSkipNonNumeric(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('fibonacci'));
		$this->stubCommonAggregates();
		$this->cardMapper->method('doneTimeline')->willReturn([]);
		$this->cardMapper->method('estimateByStack')->willReturn([
			['stackId' => 42, 'estimate' => '3'],
			['stackId' => 42, 'estimate' => '5'],
			['stackId' => 42, 'estimate' => ''],   // non-numeric, skipped
		]);
		$this->cardAssigneeMapper->method('estimateByAssigneeForBoard')->willReturn([
			['uid' => 'alice', 'estimate' => '8'],
		]);

		$dto = $this->service->boardStats(1);

		self::assertSame([['stackId' => 42, 'total' => 8.0]], $dto['estimateByStack']);
		self::assertSame([['uid' => 'alice', 'total' => 8.0]], $dto['estimateByAssignee']);
	}

	public function testEstimatePanelsNullForTextualScale(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('tshirt'));
		$this->stubCommonAggregates();
		$this->cardMapper->method('doneTimeline')->willReturn([]);
		$this->cardMapper->expects(self::never())->method('estimateByStack');

		$dto = $this->service->boardStats(1);
		self::assertNull($dto['estimateByStack']);
	}

	public function testTimelineBucketsTimestampsByUtcDay(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('none'));
		$this->stubCommonAggregates();
		// Two timestamps on 2026-01-02 (UTC) + one on 2026-01-03.
		$jan2 = gmmktime(1, 0, 0, 1, 2, 2026);
		$jan2b = gmmktime(23, 0, 0, 1, 2, 2026);
		$jan3 = gmmktime(5, 0, 0, 1, 3, 2026);
		$this->cardMapper->method('doneTimeline')->willReturn([$jan2, $jan3, $jan2b]);

		$dto = $this->service->boardStats(1);

		self::assertSame([
			['day' => '2026-01-02', 'count' => 2],
			['day' => '2026-01-03', 'count' => 1],
		], $dto['throughput']);
	}

	private const DAY = 86400;
	private const WEEK = 604800;

	public function testVelocityRollsCompletionsIntoWeeklyBucketsWithTrend(): void {
		$now = time();
		// Current flow window (last 4 weeks = 28d): 3 done this week, 1 done ~2
		// weeks ago. Prior window (weeks 5-8 back): 1 done ~5 weeks ago. Current
		// total (4) > prior total (1) ⇒ trend up. Non-numeric scale ⇒ points null.
		$completions = [
			$this->done($now - 1 * self::DAY),
			$this->done($now - 2 * self::DAY),
			$this->done($now - 3 * self::DAY),
			$this->done($now - 15 * self::DAY),
			$this->done($now - 36 * self::DAY),
		];
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('none'));
		$this->stubCommonAggregates($completions);
		$this->cardMapper->method('doneTimeline')->willReturn([]);

		$velocity = $this->service->boardStats(1)['velocity'];

		self::assertSame(4, $velocity['weeks']);
		self::assertSame(28, $velocity['windowDays']);
		self::assertCount(4, $velocity['weekly']);
		// 4 cards over 4 weeks in the current window ⇒ 1.0/week.
		self::assertSame(1.0, $velocity['cardsPerWeek']);
		self::assertSame('up', $velocity['cardsTrend']);
		// Non-numeric scale ⇒ points suppressed.
		self::assertNull($velocity['pointsPerWeek']);
		self::assertNull($velocity['pointsTrend']);
		self::assertNull($velocity['weekly'][0]['points']);
		// The most recent bucket (last row, oldest-first) holds the 3 recent cards.
		self::assertSame(3, $velocity['weekly'][3]['cards']);
	}

	public function testVelocitySumsEstimatePointsPerWeekOnNumericScale(): void {
		$now = time();
		// Two cards done this week worth 3 + 5 points; one worth 8 last week.
		$completions = [
			$this->done($now - 1 * self::DAY, '3'),
			$this->done($now - 2 * self::DAY, '5'),
			$this->done($now - 8 * self::DAY, '8'),
			$this->done($now - 3 * self::DAY, ''),   // non-numeric token: card counts, no points
		];
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('fibonacci'));
		$this->stubCommonAggregates($completions);
		$this->cardMapper->method('doneTimeline')->willReturn([]);
		// Estimate-panel queries still run for a numeric board.
		$this->cardMapper->method('estimateByStack')->willReturn([]);
		$this->cardAssigneeMapper->method('estimateByAssigneeForBoard')->willReturn([]);

		$velocity = $this->service->boardStats(1)['velocity'];

		// 16 points over 4 weeks ⇒ 4.0/week.
		self::assertSame(4.0, $velocity['pointsPerWeek']);
		self::assertIsString($velocity['pointsTrend']);
		// Most recent bucket: 3 + 5 = 8 points, 3 cards (incl. the empty-token one).
		self::assertSame(8.0, $velocity['weekly'][3]['points']);
		self::assertSame(3, $velocity['weekly'][3]['cards']);
	}

	public function testVelocityEmptyWindowIsZeroAndFlat(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('none'));
		$this->stubCommonAggregates([]);
		$this->cardMapper->method('doneTimeline')->willReturn([]);

		$velocity = $this->service->boardStats(1)['velocity'];

		self::assertSame(0.0, $velocity['cardsPerWeek']);
		self::assertSame('flat', $velocity['cardsTrend']);
		self::assertNull($velocity['pointsPerWeek']);
	}

	public function testCycleTimeMedianAndAverageOverWindow(): void {
		$now = time();
		// Three cards done in-window with create→done spans of 2, 4 and 6 days.
		$completions = [
			['createdAt' => $now - 3 * self::DAY, 'doneAt' => $now - 1 * self::DAY, 'estimate' => null], // 2d
			['createdAt' => $now - 6 * self::DAY, 'doneAt' => $now - 2 * self::DAY, 'estimate' => null], // 4d
			['createdAt' => $now - 9 * self::DAY, 'doneAt' => $now - 3 * self::DAY, 'estimate' => null], // 6d
			// Done outside the current 28d flow window (older than window) ⇒ excluded.
			['createdAt' => $now - 60 * self::DAY, 'doneAt' => $now - 40 * self::DAY, 'estimate' => null],
		];
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('none'));
		$this->stubCommonAggregates($completions);
		$this->cardMapper->method('doneTimeline')->willReturn([]);

		$cycle = $this->service->boardStats(1)['cycleTime'];

		self::assertSame(28, $cycle['windowDays']);
		self::assertSame(3, $cycle['sampleSize']);
		self::assertSame(4.0, $cycle['medianDays']);
		self::assertSame(4.0, $cycle['averageDays']);
	}

	public function testFlowWindowIsWeekAlignedAndConsistentAcrossVelocityAndCycleTime(): void {
		$now = time();
		// A card done 5 days ago is squarely inside the 28d current window - it
		// must count in BOTH velocity's current total AND cycle time. A card done
		// 29 days ago is just OUTSIDE the 28d window (bucket 4 = prior window for
		// velocity, and before cycle time's windowStart) - it must appear in
		// NEITHER velocity's current total NOR the cycle-time sample. This pins
		// the single shared week-aligned window (regression guard for the earlier
		// 28d-vs-30d split).
		$completions = [
			['createdAt' => $now - 7 * self::DAY, 'doneAt' => $now - 5 * self::DAY, 'estimate' => null],
			['createdAt' => $now - 31 * self::DAY, 'doneAt' => $now - 29 * self::DAY, 'estimate' => null],
		];
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('none'));
		$this->stubCommonAggregates($completions);
		$this->cardMapper->method('doneTimeline')->willReturn([]);

		$dto = $this->service->boardStats(1);

		// Velocity current window: only the 5-day card ⇒ 1 card / 4 weeks = 0.25.
		self::assertSame(0.25, $dto['velocity']['cardsPerWeek']);
		// The 29-day card lands in the prior window (bucket 4), so current > 0 and
		// prior > 0 with equal counts (1 vs 1) ⇒ flat.
		self::assertSame('flat', $dto['velocity']['cardsTrend']);
		// Cycle time: only the in-window (5-day) card is measured.
		self::assertSame(1, $dto['cycleTime']['sampleSize']);
		self::assertSame(28, $dto['cycleTime']['windowDays']);
	}

	public function testCycleTimeEmptySampleIsNull(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board('none'));
		$this->stubCommonAggregates([]);
		$this->cardMapper->method('doneTimeline')->willReturn([]);

		$cycle = $this->service->boardStats(1)['cycleTime'];

		self::assertSame(0, $cycle['sampleSize']);
		self::assertNull($cycle['medianDays']);
		self::assertNull($cycle['averageDays']);
	}

	// ── Project analytics (card-id-set variant, #3568) ────────────────────────

	public function testProjectStatsComposesCardSetAggregatesAndOmitsBoardSpecificPanels(): void {
		$cardIds = [9, 11, 42];
		$this->cardMapper->expects(self::once())->method('countByPriorityForCards')->with($cardIds)
			->willReturn([['priority' => 4, 'count' => 2]]);
		$this->cardAssigneeMapper->expects(self::once())->method('countByAssigneeForCards')->with($cardIds)
			->willReturn([['uid' => 'alice', 'count' => 3]]);
		$this->cardLabelMapper->expects(self::once())->method('countByLabelForCards')->with($cardIds)
			->willReturn([['boardId' => 1, 'labelId' => 9, 'count' => 1]]);
		$this->cardMapper->method('doneTimelineForCards')->willReturn([]);
		$this->cardMapper->method('createdTimelineForCards')->willReturn([]);
		$this->cardMapper->method('doneCycleTimesForCards')->willReturn([]);
		$this->cardMapper->method('agingCountForCards')->willReturn(5);
		$this->cardMapper->method('overdueCountForCards')->willReturn(1);
		$this->checklistItemMapper->method('progressByCards')->willReturn([
			9 => ['total' => 4, 'done' => 2],
			11 => ['total' => 1, 'done' => 1],
		]);
		$this->commentMapper->method('countRecentForCards')->willReturn(7);
		// A project never reads a board scale - the board-specific board* methods
		// and estimate panels must never run over a card set.
		$this->boardMapper->expects(self::never())->method('find');
		$this->cardMapper->expects(self::never())->method('countByStack');
		$this->cardMapper->expects(self::never())->method('estimateByStack');

		$dto = $this->service->projectStats($cardIds);

		self::assertArrayNotHasKey('byStack', $dto);
		self::assertArrayNotHasKey('estimateByStack', $dto);
		self::assertArrayNotHasKey('estimateByAssignee', $dto);
		self::assertSame([['priority' => 4, 'count' => 2]], $dto['byPriority']);
		self::assertSame([['uid' => 'alice', 'count' => 3]], $dto['byAssignee']);
		self::assertSame([['boardId' => 1, 'labelId' => 9, 'count' => 1]], $dto['byLabel']);
		self::assertSame(5, $dto['aging']['count']);
		self::assertSame(1, $dto['overdue']);
		self::assertSame(7, $dto['commentActivity']);
		self::assertSame(['total' => 5, 'done' => 3], $dto['checklist']);
		self::assertSame(3, $dto['cardCount']);
	}

	public function testProjectStatsNeverSumsPointsAcrossMixedScales(): void {
		// Completions carry numeric-looking estimate tokens, but a project spans
		// boards on different scales, so points must never be summed - velocity
		// reports cards only, with points null regardless of the tokens.
		$now = time();
		$completions = [
			$this->done($now - 1 * self::DAY, '3'),
			$this->done($now - 2 * self::DAY, '5'),
		];
		$this->cardMapper->method('countByPriorityForCards')->willReturn([]);
		$this->cardAssigneeMapper->method('countByAssigneeForCards')->willReturn([]);
		$this->cardLabelMapper->method('countByLabelForCards')->willReturn([]);
		$this->cardMapper->method('doneTimelineForCards')->willReturn([]);
		$this->cardMapper->method('createdTimelineForCards')->willReturn([]);
		$this->cardMapper->method('doneCycleTimesForCards')->willReturn($completions);
		$this->cardMapper->method('agingCountForCards')->willReturn(0);
		$this->cardMapper->method('overdueCountForCards')->willReturn(0);
		$this->checklistItemMapper->method('progressByCards')->willReturn([]);
		$this->commentMapper->method('countRecentForCards')->willReturn(0);

		$velocity = $this->service->projectStats([9, 11])['velocity'];

		self::assertSame(2, $velocity['weekly'][3]['cards']);
		self::assertNull($velocity['pointsPerWeek']);
		self::assertNull($velocity['pointsTrend']);
		self::assertNull($velocity['weekly'][3]['points']);
	}

	public function testProjectStatsEmptyCardSetYieldsZeroedDto(): void {
		// No cards ⇒ every card-set mapper returns its empty/zero result; the DTO
		// composes cleanly with no throw and an all-zero shape.
		$this->cardMapper->method('countByPriorityForCards')->with([])->willReturn([]);
		$this->cardAssigneeMapper->method('countByAssigneeForCards')->with([])->willReturn([]);
		$this->cardLabelMapper->method('countByLabelForCards')->with([])->willReturn([]);
		$this->cardMapper->method('doneTimelineForCards')->willReturn([]);
		$this->cardMapper->method('createdTimelineForCards')->willReturn([]);
		$this->cardMapper->method('doneCycleTimesForCards')->willReturn([]);
		$this->cardMapper->method('agingCountForCards')->willReturn(0);
		$this->cardMapper->method('overdueCountForCards')->willReturn(0);
		$this->checklistItemMapper->method('progressByCards')->willReturn([]);
		$this->commentMapper->method('countRecentForCards')->willReturn(0);

		$dto = $this->service->projectStats([]);

		self::assertSame(0, $dto['cardCount']);
		self::assertSame([], $dto['byPriority']);
		self::assertSame(0, $dto['overdue']);
		self::assertSame(['total' => 0, 'done' => 0], $dto['checklist']);
		self::assertSame(0.0, $dto['velocity']['cardsPerWeek']);
		self::assertNull($dto['cycleTime']['medianDays']);
	}

	/**
	 * @return array{createdAt: int, doneAt: int, estimate: ?string}
	 */
	private function done(int $doneAt, ?string $estimate = null): array {
		// created a day before done by default; cycle-time tests build spans explicitly.
		return ['createdAt' => $doneAt - self::DAY, 'doneAt' => $doneAt, 'estimate' => $estimate];
	}
}

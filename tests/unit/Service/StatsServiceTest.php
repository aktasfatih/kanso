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

	private function stubCommonAggregates(): void {
		$this->cardMapper->method('countByStack')->willReturn([['stackId' => 42, 'count' => 3]]);
		$this->cardMapper->method('countByPriority')->willReturn([['priority' => 4, 'count' => 1]]);
		$this->cardAssigneeMapper->method('countByAssigneeForBoard')->willReturn([['uid' => 'alice', 'count' => 2]]);
		$this->cardLabelMapper->method('countByLabelForBoard')->willReturn([['labelId' => 9, 'count' => 5]]);
		// doneTimeline is stubbed per-test (the timeline test overrides it);
		// createdTimeline defaults to empty for every test here.
		$this->cardMapper->method('createdTimeline')->willReturn([]);
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
}

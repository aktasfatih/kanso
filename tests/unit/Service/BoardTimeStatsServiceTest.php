<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\BoardTimeStatsService;
use OCA\Kanso\Service\InvalidInputException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BoardTimeStatsServiceTest extends TestCase {
	private CardMapper&MockObject $cardMapper;
	private IUserManager&MockObject $userManager;
	private BoardTimeStatsService $service;
	private Board $board;
	private ViewerContext $viewer;

	protected function setUp(): void {
		parent::setUp();
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn((int)strtotime('2026-11-18 12:00:00 UTC'));
		$this->service = new BoardTimeStatsService($this->cardMapper, $this->userManager, $clock);
		$this->board = new Board();
		$this->board->setId(7);
		$this->viewer = ViewerContext::forMember('alice', 7, ViewerContext::ROLE_INTERNAL, true);
	}

	public function testSeparatesHumanAndAiActorTotalsAndCachesDisplayNames(): void {
		$this->cardMapper->expects(self::once())->method('timeSpentByUser')
			->with(7, strtotime('2026-11-01 UTC'), strtotime('2026-11-19 UTC'), $this->viewer)
			->willReturn([
				['uid' => 'alice', 'actorName' => null, 'seconds' => 3600],
				['uid' => 'alice', 'actorName' => 'Codex', 'seconds' => 1800],
				['uid' => 'alice', 'actorName' => 'Claude Code', 'seconds' => 900],
				['uid' => 'removed-user', 'actorName' => null, 'seconds' => 300],
			]);
		$this->userManager->expects(self::exactly(2))->method('getDisplayName')
			->willReturnMap([['alice', 'Alice'], ['removed-user', null]]);

		$stats = $this->service->get($this->board, $this->viewer, '2026-11-01', '2026-11-18');

		self::assertTrue($stats['enabled']);
		self::assertSame(6600, $stats['totalSeconds']);
		self::assertSame('2026-11-01', $stats['from']);
		self::assertSame('2026-11-18', $stats['to']);
		self::assertSame([null, 'Codex', 'Claude Code', null], array_column($stats['byUser'], 'actorName'));
		self::assertSame(['Alice', 'Alice', 'Alice', 'removed-user'], array_column($stats['byUser'], 'displayName'));
	}

	public function testDefaultsToThirtyInclusiveUtcDates(): void {
		$this->cardMapper->expects(self::once())->method('timeSpentByUser')
			->with(7, strtotime('2026-10-20 UTC'), strtotime('2026-11-19 UTC'), $this->viewer)
			->willReturn([]);

		$stats = $this->service->get($this->board, $this->viewer);

		self::assertSame('2026-10-20', $stats['from']);
		self::assertSame('2026-11-18', $stats['to']);
		self::assertSame(0, $stats['totalSeconds']);
		self::assertSame([], $stats['byUser']);
	}

	public function testTheEndDateIncludesItsEntireUtcDay(): void {
		$this->cardMapper->expects(self::once())->method('timeSpentByUser')
			->with(7, strtotime('2026-03-08 00:00:00 UTC'), strtotime('2026-03-09 00:00:00 UTC'), $this->viewer)
			->willReturn([]);

		$this->service->get($this->board, $this->viewer, '2026-03-08', '2026-03-08');
	}

	public function testAllowsAnEntireLeapYear(): void {
		$this->cardMapper->expects(self::once())->method('timeSpentByUser')
			->with(7, strtotime('2028-01-01 UTC'), strtotime('2029-01-01 UTC'), $this->viewer)
			->willReturn([]);

		$this->service->get($this->board, $this->viewer, '2028-01-01', '2028-12-31');
	}

	public function testDisabledTimeTrackingDoesNotReadHistoricalEntries(): void {
		$this->board->setDisabledCardFeatures('["timeTracking"]');
		$this->cardMapper->expects(self::never())->method('timeSpentByUser');
		$this->userManager->expects(self::never())->method('getDisplayName');

		$stats = $this->service->get($this->board, $this->viewer);

		self::assertFalse($stats['enabled']);
		self::assertSame(0, $stats['totalSeconds']);
		self::assertSame([], $stats['byUser']);
	}

	/** @return iterable<string, array{string, string}> */
	public static function invalidRanges(): iterable {
		yield 'bad format' => ['2026-1-01', '2026-11-18'];
		yield 'invalid day' => ['2026-02-30', '2026-11-18'];
		yield 'invalid end' => ['2026-11-01', '2026-11-31'];
		yield 'empty date' => ['', '2026-11-18'];
		yield 'year zero' => ['0000-01-01', '0000-01-02'];
		yield 'embedded null' => ["2026-11-01\0", '2026-11-18'];
		yield 'reversed' => ['2026-11-19', '2026-11-18'];
		yield 'too long' => ['2028-01-01', '2029-01-01'];
	}

	#[DataProvider('invalidRanges')]
	public function testRejectsInvalidRangesBeforeQuerying(string $from, string $to): void {
		$this->cardMapper->expects(self::never())->method('timeSpentByUser');
		$this->expectException(InvalidInputException::class);

		$this->service->get($this->board, $this->viewer, $from, $to);
	}
}

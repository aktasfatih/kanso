<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\ProjectForecast;
use PHPUnit\Framework\TestCase;

class ProjectForecastTest extends TestCase {
	private const WEEK = 604800;
	private const DAY = 86400;

	private int $now;
	private int $created;
	private int $nextId = 1;
	private ProjectForecast $forecast;

	protected function setUp(): void {
		parent::setUp();
		$this->now = (int)strtotime('2026-10-21 12:00:00 UTC');
		$this->created = $this->now - 10 * self::WEEK;
		$this->forecast = new ProjectForecast();
	}

	/** @return array<string, mixed> */
	private function card(int $doneAt = 0, ?string $estimate = null, string $scale = 'none', array $extra = []): array {
		return array_merge([
			'id' => $this->nextId++,
			'doneAt' => $doneAt,
			'startedAt' => 0,
			'duedate' => null,
			'estimate' => $estimate,
			'estimateScale' => $scale,
			'createdAt' => $this->created,
			'addedAt' => 0,
			'blockers' => 0,
		], $extra);
	}

	private function doneWeeksAgo(int $weeks): int {
		return $this->now - $weeks * self::WEEK - 3600;
	}

	/**
	 * @param list<int> $perWeek completions per week, oldest first
	 * @return list<array<string, mixed>>
	 */
	private function history(array $perWeek, int $open): array {
		$cards = [];
		$weeks = count($perWeek);
		foreach ($perWeek as $i => $count) {
			for ($c = 0; $c < $count; $c++) {
				$cards[] = $this->card($this->doneWeeksAgo($weeks - 1 - $i));
			}
		}
		for ($c = 0; $c < $open; $c++) {
			$cards[] = $this->card();
		}
		return $cards;
	}

	private function compute(array $cards, ?string $target, ?string $start = null): array {
		return $this->forecast->compute($cards, $this->created, $start, $target, $this->now);
	}

	public function testSteadyPaceBeforeTargetIsOnTrack(): void {
		$plan = $this->compute($this->history([2, 2, 2, 2, 2, 2], 4), '2026-11-30');

		self::assertSame('cards', $plan['basis']);
		self::assertSame(2.0, $plan['throughput']['perWeek']);
		self::assertSame(['date' => '2026-11-04', 'earliest' => '2026-11-04', 'latest' => '2026-11-04'], $plan['forecast']);
		self::assertSame(ProjectForecast::STATUS_ON_TRACK, $plan['status']);
		self::assertSame(40, $plan['daysLeft']);
	}

	public function testStalledHalfOfWeeksIsAtRiskEvenWhenAverageFits(): void {
		$plan = $this->compute($this->history([0, 0, 0, 3, 3, 3], 3), '2026-11-30');

		self::assertSame('2026-11-04', $plan['forecast']['date']);
		self::assertSame('2026-10-28', $plan['forecast']['earliest']);
		self::assertNull($plan['forecast']['latest']);
		self::assertSame(ProjectForecast::STATUS_AT_RISK, $plan['status']);
	}

	public function testBestCaseMissingTargetIsOffTrack(): void {
		$plan = $this->compute($this->history([2, 2, 2, 2, 2, 2], 4), '2026-10-25');

		self::assertSame(ProjectForecast::STATUS_OFF_TRACK, $plan['status']);
	}

	public function testPassedTargetWithOpenWorkIsLate(): void {
		$plan = $this->compute($this->history([2, 2, 2, 2, 2, 2], 4), '2026-10-20');

		self::assertSame(ProjectForecast::STATUS_LATE, $plan['status']);
		self::assertSame(-1, $plan['daysLeft']);
	}

	public function testAllDoneIsDoneRegardlessOfTarget(): void {
		$plan = $this->compute($this->history([1, 1], 0), '2026-10-01');

		self::assertSame(ProjectForecast::STATUS_DONE, $plan['status']);
		self::assertNull($plan['forecast']);
	}

	public function testNoTargetStillForecasts(): void {
		$plan = $this->compute($this->history([2, 2, 2, 2, 2, 2], 4), null);

		self::assertSame(ProjectForecast::STATUS_NO_TARGET, $plan['status']);
		self::assertSame('2026-11-04', $plan['forecast']['date']);
		self::assertNull($plan['daysLeft']);
	}

	public function testSingleCompletionIsNotEnoughData(): void {
		$plan = $this->compute($this->history([0, 0, 0, 0, 0, 1], 5), '2026-12-31');

		self::assertNull($plan['forecast']);
		self::assertSame(ProjectForecast::STATUS_NOT_ENOUGH_DATA, $plan['status']);
	}

	public function testYoungProjectAveragesOnlyTheWeeksItExisted(): void {
		$this->created = $this->now - 10 * self::DAY;
		$cards = [
			$this->card($this->doneWeeksAgo(0)),
			$this->card($this->doneWeeksAgo(0)),
			$this->card($this->doneWeeksAgo(1)),
			$this->card($this->doneWeeksAgo(1)),
			$this->card(),
		];

		$plan = $this->compute($cards, null);

		self::assertSame(2, $plan['throughput']['weeks']);
		self::assertSame(2.0, $plan['throughput']['perWeek']);
	}

	public function testStartDateBeforeCreationWidensTheWindow(): void {
		$this->created = $this->now - 3 * self::DAY;

		$plan = $this->compute($this->history([1, 1, 1], 1), null, '2026-09-01');

		self::assertSame(ProjectForecast::WINDOW_WEEKS, $plan['throughput']['weeks']);
	}

	public function testMixedScalesNormalizeAndUnestimatedCardsTakeTheMedian(): void {
		$cards = [
			$this->card($this->doneWeeksAgo(0), 'M', 'tshirt'),
			$this->card($this->doneWeeksAgo(1), '5', 'fibonacci'),
			$this->card(0, '16', 'hours'),
			$this->card(0, null, 'fibonacci'),
		];

		$plan = $this->compute($cards, null);

		self::assertSame('points', $plan['basis']);
		self::assertSame([
			'total' => 21.0,
			'done' => 8.0,
			'remaining' => 13.0,
			'estimatedCount' => 3,
			'unestimatedCount' => 1,
			'assumedPerCard' => 5.0,
		], $plan['points']);
	}

	public function testUnreadableEstimateTokensAreIgnored(): void {
		self::assertNull(ProjectForecast::normalize('XXL', 'tshirt'));
		self::assertNull(ProjectForecast::normalize('3', 'none'));
		self::assertNull(ProjectForecast::normalize('abc', 'fibonacci'));
		self::assertSame(8.0, ProjectForecast::normalize('xl', 'tshirt'));
	}

	public function testCountsBlockedOverdueAndInProgress(): void {
		$cards = [
			$this->card(0, null, 'none', ['blockers' => 1, 'startedAt' => 5]),
			$this->card(0, null, 'none', ['startedAt' => 5, 'duedate' => '2026-10-01T00:00:00+00:00']),
			$this->card(0, null, 'none', ['duedate' => '2026-12-01T00:00:00+00:00']),
			$this->card($this->doneWeeksAgo(0), null, 'none', ['duedate' => '2026-10-01T00:00:00+00:00']),
		];

		$plan = $this->compute($cards, null);

		self::assertSame(1, $plan['blocked']);
		self::assertSame(1, $plan['inProgress']);
		self::assertSame(1, $plan['overdue']);
		self::assertSame(3, $plan['open']);
	}

	public function testBurnupTracksScopeGrowthAndNeverCountsDoneBeforeAdded(): void {
		$this->created = $this->now - 4 * self::DAY;
		$cards = [
			$this->card(0, null, 'none', ['addedAt' => $this->now - 4 * self::DAY]),
			$this->card($this->now - 3 * self::DAY, null, 'none', ['addedAt' => $this->now - 1 * self::DAY]),
		];

		$burnup = $this->compute($cards, null)['burnup'];

		self::assertSame(['2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'], array_column($burnup, 'date'));
		self::assertSame([1, 1, 1, 2, 2], array_column($burnup, 'scope'));
		self::assertSame([0, 0, 0, 1, 1], array_column($burnup, 'done'));
		self::assertNull($burnup[0]['scopePoints']);
	}

	public function testBurnupIsCappedForLongProjects(): void {
		$this->created = $this->now - 400 * self::DAY;

		$burnup = $this->compute([$this->card()], null)['burnup'];

		self::assertLessThanOrEqual(41, count($burnup));
		self::assertSame('2026-10-21', end($burnup)['date']);
	}
}

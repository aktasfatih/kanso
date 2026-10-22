<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\EstimateScale;

/**
 * Progress, forecast, health and burn-up for one project, computed from the
 * already visibility-scoped card rows {@see ProjectService::plan()} resolves.
 * Pure: no I/O, `$now` injected.
 */
final class ProjectForecast {
	public const STATUS_DONE = 'done';
	public const STATUS_ON_TRACK = 'on_track';
	public const STATUS_AT_RISK = 'at_risk';
	public const STATUS_OFF_TRACK = 'off_track';
	public const STATUS_LATE = 'late';
	public const STATUS_NO_TARGET = 'no_target';
	public const STATUS_NOT_ENOUGH_DATA = 'not_enough_data';

	public const WINDOW_WEEKS = 6;
	private const MIN_COMPLETIONS = 2;
	private const MAX_BURNUP_POINTS = 40;
	private const DAY = 86400;
	private const WEEK = 604800;

	// Time scales map onto this ladder by position, so a board estimating in
	// hours and one estimating in points can share a project total.
	private const RELATIVE_LADDER = [1.0, 2.0, 3.0, 5.0, 8.0, 13.0, 21.0];

	/**
	 * @param list<array<string, mixed>> $cards
	 * @return array<string, mixed>
	 */
	public function compute(array $cards, int $projectCreatedAt, ?string $startDate, ?string $targetDate, int $now): array {
		$since = $projectCreatedAt;
		if ($startDate !== null) {
			$since = min($since, (int)strtotime($startDate . ' 00:00:00 UTC'));
		}
		$since = min($since, $now);
		$today = gmdate('Y-m-d', $now);

		$points = $this->points($cards);
		$total = count($cards);
		$done = 0;
		$overdue = 0;
		$blocked = 0;
		$inProgress = 0;
		foreach ($cards as $card) {
			if ((int)$card['doneAt'] > 0) {
				$done++;
				continue;
			}
			if ((int)($card['blockers'] ?? 0) > 0) {
				$blocked++;
			} elseif ((int)$card['startedAt'] > 0) {
				$inProgress++;
			}
			if ($card['duedate'] !== null && (int)strtotime((string)$card['duedate']) < $now) {
				$overdue++;
			}
		}

		$usePoints = $points !== null;
		$weekly = $this->weeklyThroughput($cards, $points['byCard'] ?? null, $since, $now);
		$completions = array_sum(array_column($weekly, 'cards'));
		$amounts = array_column($weekly, $usePoints ? 'points' : 'cards');
		$remaining = $usePoints ? $points['remaining'] : (float)($total - $done);

		$forecast = null;
		if ($remaining > 0 && $completions >= self::MIN_COMPLETIONS) {
			$forecast = $this->forecast($amounts, $remaining, $now);
		}

		return [
			'total' => $total,
			'done' => $done,
			'open' => $total - $done,
			'overdue' => $overdue,
			'blocked' => $blocked,
			'inProgress' => $inProgress,
			'points' => $points !== null ? array_diff_key($points, ['byCard' => true]) : null,
			'basis' => $usePoints ? 'points' : 'cards',
			'throughput' => [
				'weeks' => count($weekly),
				'perWeek' => $this->rate($amounts),
				'weekly' => $weekly,
			],
			'forecast' => $forecast,
			'startDate' => $startDate,
			'targetDate' => $targetDate,
			'daysLeft' => $targetDate !== null ? $this->daysBetween($today, $targetDate) : null,
			'status' => $this->status($remaining, $forecast, $targetDate, $today),
			'burnup' => $this->burnup($cards, $points['byCard'] ?? null, $since, $now),
		];
	}

	/**
	 * Project points for one card: its value on the board's scale, except time
	 * scales, which use their position on the relative ladder.
	 */
	public static function normalize(?string $estimate, string $scale, ?float $value): ?float {
		if ($estimate === null || $estimate === '' || $value === null) {
			return null;
		}
		if (in_array($scale, EstimateScale::TIME_SCALES, true)) {
			$position = array_search($estimate, array_map('strval', array_keys(EstimateScale::PRESETS[$scale])), true);
			return $position === false ? null : (self::RELATIVE_LADDER[$position] ?? null);
		}
		return $value;
	}

	/**
	 * Per-card points (unestimated cards count as the median estimate), or null
	 * when no card in the project carries a readable estimate.
	 *
	 * @param list<array<string, mixed>> $cards
	 * @return array{total: float, done: float, remaining: float, estimatedCount: int, unestimatedCount: int, assumedPerCard: float, byCard: array<int, float>}|null
	 */
	private function points(array $cards): ?array {
		$known = [];
		foreach ($cards as $card) {
			$value = self::normalize(
				isset($card['estimate']) ? (string)$card['estimate'] : null,
				(string)($card['estimateScale'] ?? EstimateScale::NONE),
				isset($card['estimateValue']) ? (float)$card['estimateValue'] : null,
			);
			if ($value !== null) {
				$known[(int)$card['id']] = $value;
			}
		}
		if ($known === []) {
			return null;
		}

		$assumed = $this->median(array_values($known));
		$byCard = [];
		$total = 0.0;
		$done = 0.0;
		foreach ($cards as $card) {
			$id = (int)$card['id'];
			$value = $known[$id] ?? $assumed;
			$byCard[$id] = $value;
			$total += $value;
			if ((int)$card['doneAt'] > 0) {
				$done += $value;
			}
		}

		return [
			'total' => round($total, 2),
			'done' => round($done, 2),
			'remaining' => round($total - $done, 2),
			'estimatedCount' => count($known),
			'unestimatedCount' => count($cards) - count($known),
			'assumedPerCard' => round($assumed, 2),
			'byCard' => $byCard,
		];
	}

	/**
	 * Completions per 7-day bucket, oldest first, over the weeks the project has
	 * existed (capped at WINDOW_WEEKS) so a young project isn't averaged over
	 * weeks it didn't exist.
	 *
	 * @param list<array<string, mixed>> $cards
	 * @param array<int, float>|null $byCard normalized points per card id
	 * @return list<array{week: string, cards: int, points: float}>
	 */
	private function weeklyThroughput(array $cards, ?array $byCard, int $since, int $now): array {
		$weeks = max(1, min(self::WINDOW_WEEKS, (int)ceil(($now - $since) / self::WEEK)));
		$buckets = array_fill(0, $weeks, ['cards' => 0, 'points' => 0.0]);
		foreach ($cards as $card) {
			$doneAt = (int)$card['doneAt'];
			if ($doneAt <= 0 || $doneAt > $now) {
				continue;
			}
			$age = intdiv($now - $doneAt, self::WEEK);
			if ($age >= $weeks) {
				continue;
			}
			$index = $weeks - 1 - $age;
			$buckets[$index]['cards']++;
			$buckets[$index]['points'] += $byCard[(int)$card['id']] ?? 0.0;
		}

		$rows = [];
		foreach ($buckets as $i => $bucket) {
			$rows[] = [
				'week' => gmdate('Y-m-d', $now - ($weeks - $i) * self::WEEK),
				'cards' => $bucket['cards'],
				'points' => round($bucket['points'], 2),
			];
		}
		return $rows;
	}

	/**
	 * Expected date at the average weekly pace, bracketed by the pace of the
	 * better and worse halves of the observed weeks. `latest` is null when the
	 * worse half made no progress at all (no finite pessimistic date).
	 *
	 * @param list<int|float> $amounts per-week completed work, oldest first
	 * @return array{date: string, earliest: string, latest: ?string}|null
	 */
	private function forecast(array $amounts, float $remaining, int $now): ?array {
		$rate = $this->rate($amounts);
		if ($rate <= 0) {
			return null;
		}
		$sorted = array_map('floatval', $amounts);
		sort($sorted);
		$n = count($sorted);
		$low = $n >= 2 ? array_slice($sorted, 0, intdiv($n, 2)) : $sorted;
		$high = $n >= 2 ? array_slice($sorted, intdiv($n, 2)) : $sorted;
		$optimistic = $this->rate($high);
		$pessimistic = $this->rate($low);

		return [
			'date' => $this->dateAfter($now, $remaining / $rate),
			'earliest' => $this->dateAfter($now, $remaining / $optimistic),
			'latest' => $pessimistic > 0 ? $this->dateAfter($now, $remaining / $pessimistic) : null,
		];
	}

	/**
	 * @param array{date: string, earliest: string, latest: ?string}|null $forecast
	 */
	private function status(float $remaining, ?array $forecast, ?string $targetDate, string $today): string {
		if ($remaining <= 0) {
			return self::STATUS_DONE;
		}
		if ($targetDate === null) {
			return self::STATUS_NO_TARGET;
		}
		if ($targetDate < $today) {
			return self::STATUS_LATE;
		}
		if ($forecast === null) {
			return self::STATUS_NOT_ENOUGH_DATA;
		}
		if ($forecast['earliest'] > $targetDate) {
			return self::STATUS_OFF_TRACK;
		}
		if ($forecast['date'] > $targetDate || $forecast['latest'] === null || $forecast['latest'] > $targetDate) {
			return self::STATUS_AT_RISK;
		}
		return self::STATUS_ON_TRACK;
	}

	/**
	 * Scope and done over time. A card's scope entry is when it joined the
	 * project (or, for rows older than `added_at`, when it was created, but not
	 * before the project began); it can't count as done before it joined.
	 *
	 * @param list<array<string, mixed>> $cards
	 * @param array<int, float>|null $byCard normalized points per card id
	 * @return list<array{date: string, scope: int, done: int, scopePoints: ?float, donePoints: ?float}>
	 */
	private function burnup(array $cards, ?array $byCard, int $since, int $now): array {
		$events = [];
		foreach ($cards as $card) {
			$added = (int)($card['addedAt'] ?? 0);
			if ($added <= 0) {
				$added = max((int)($card['createdAt'] ?? 0), $since);
			}
			$doneAt = (int)$card['doneAt'];
			$events[] = [
				'added' => min($added, $now),
				'done' => $doneAt > 0 ? min(max($doneAt, $added), $now) : null,
				'points' => $byCard[(int)$card['id']] ?? 0.0,
			];
		}

		$start = (int)strtotime(gmdate('Y-m-d', $since) . ' 00:00:00 UTC');
		$end = (int)strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
		$days = intdiv($end - $start, self::DAY);
		$step = max(1, (int)ceil($days / (self::MAX_BURNUP_POINTS - 1)));

		$samples = [];
		for ($day = 0; $day < $days; $day += $step) {
			$samples[] = $start + $day * self::DAY;
		}
		$samples[] = $end;

		$rows = [];
		foreach ($samples as $dayStart) {
			$cutoff = $dayStart === $end ? $now : $dayStart + self::DAY - 1;
			$scope = 0;
			$done = 0;
			$scopePoints = 0.0;
			$donePoints = 0.0;
			foreach ($events as $event) {
				if ($event['added'] <= $cutoff) {
					$scope++;
					$scopePoints += $event['points'];
				}
				if ($event['done'] !== null && $event['done'] <= $cutoff) {
					$done++;
					$donePoints += $event['points'];
				}
			}
			$rows[] = [
				'date' => gmdate('Y-m-d', $dayStart),
				'scope' => $scope,
				'done' => $done,
				'scopePoints' => $byCard !== null ? round($scopePoints, 2) : null,
				'donePoints' => $byCard !== null ? round($donePoints, 2) : null,
			];
		}
		return $rows;
	}

	/** @param list<int|float> $values */
	private function rate(array $values): float {
		return $values === [] ? 0.0 : round((float)array_sum($values) / (float)count($values), 2);
	}

	/** @param list<float> $values non-empty */
	private function median(array $values): float {
		sort($values);
		$n = count($values);
		$mid = intdiv($n, 2);
		return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2.0;
	}

	private function dateAfter(int $now, float $weeks): string {
		return gmdate('Y-m-d', $now + (int)ceil($weeks * 7.0) * self::DAY);
	}

	private function daysBetween(string $from, string $to): int {
		return intdiv((int)strtotime($to . ' 00:00:00 UTC') - (int)strtotime($from . ' 00:00:00 UTC'), self::DAY);
	}
}

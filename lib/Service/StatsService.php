<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\EstimateScale;

/**
 * Read-only board analytics. Composes the per-mapper aggregate queries into
 * one flat DTO for GET /api/boards/{id}/stats. Every aggregate is board-scoped
 * (filtered by board_id) and open-scoped (deleted_at = 0) at the query level,
 * so there is no cross-board leak and no per-row work here.
 *
 * Alongside the plain snapshot it derives two flow metrics from data already
 * recorded (created_at / done_at / estimate) - velocity (cards, and estimate
 * points on numeric scales, completed per week with a rolling average and an
 * up/down/flat direction vs the prior period) and lead/cycle time (median and
 * average create→done days). No new column is needed for either. Burndown and
 * forecasting remain out of scope. All timelines and roll-ups are computed in
 * PHP from unix timestamps so the SQL stays dialect-clean.
 */
class StatsService {
	/**
	 * Aging threshold in days - a fixed product decision, not configurable. An
	 * open card counts as "aging" once it has existed at least this long
	 * (measured from creation; there is no last-moved column).
	 */
	private const AGING_DAYS = 14;

	/** Look-back window (days) for the throughput / created / comment timelines. */
	private const WINDOW_DAYS = 30;

	/**
	 * Look-back window for the flow metrics (velocity + cycle time), expressed
	 * in whole weeks so the weekly roll-up buckets align exactly with the window
	 * (no ragged final week). The trend compares this window against an equal
	 * prior window, so the completions query spans twice this many weeks.
	 */
	private const FLOW_WEEKS = 4;

	private const DAY_SECONDS = 86400;

	private const WEEK_SECONDS = 604800;

	public function __construct(
		private BoardMapper $boardMapper,
		private CardMapper $cardMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardLabelMapper $cardLabelMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CommentMapper $commentMapper,
	) {
	}

	/**
	 * The full board-stats DTO. The caller (BoardStatsController) has already
	 * asserted PERMISSION_READ via BoardService::find(), so this method assumes
	 * the board is readable and only reads the estimate scale off it (a second
	 * lightweight load - the controller does not pass the entity through).
	 *
	 * @return array{
	 *     byStack: list<array{stackId: int, count: int}>,
	 *     byPriority: list<array{priority: int, count: int}>,
	 *     byAssignee: list<array{uid: string, count: int}>,
	 *     byLabel: list<array{labelId: int, count: int}>,
	 *     throughput: list<array{day: string, count: int}>,
	 *     created: list<array{day: string, count: int}>,
	 *     estimateByStack: null|list<array{stackId: int, total: float}>,
	 *     estimateByAssignee: null|list<array{uid: string, total: float}>,
	 *     aging: array{days: int, count: int},
	 *     overdue: int,
	 *     checklist: array{total: int, done: int},
	 *     commentActivity: int,
	 *     velocity: array{
	 *         weeks: int,
	 *         windowDays: int,
	 *         cardsPerWeek: float,
	 *         cardsTrend: string,
	 *         pointsPerWeek: null|float,
	 *         pointsTrend: null|string,
	 *         weekly: list<array{week: string, cards: int, points: null|float}>
	 *     },
	 *     cycleTime: array{windowDays: int, sampleSize: int, medianDays: null|float, averageDays: null|float}
	 * }
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function boardStats(int $boardId, ViewerContext $viewer): array {
		$now = time();
		$windowStart = $now - self::WINDOW_DAYS * self::DAY_SECONDS;
		$agingCutoff = $now - self::AGING_DAYS * self::DAY_SECONDS;
		// The flow metrics share ONE week-aligned window (FLOW_WEEKS) so velocity
		// and cycle time measure the exact same span; the buckets align with it
		// with no ragged final week. Velocity's up/down/flat trend needs the
		// equal-length prior window too, so completions are read over 2x it.
		$flowWindowStart = $now - self::FLOW_WEEKS * self::WEEK_SECONDS;
		$flowFetchStart = $now - 2 * self::FLOW_WEEKS * self::WEEK_SECONDS;

		$board = $this->boardMapper->find($boardId);
		$numericScale = $this->isNumericScale($board);

		$completions = $this->cardMapper->doneCycleTimes($boardId, $flowFetchStart, $now, $viewer);

		// Visibility (#3743): EVERY aggregate below is viewer-scoped - a
		// hidden card must not surface through a count, a timeline bucket,
		// an estimate sum or a velocity figure either.
		return [
			'byStack' => $this->cardMapper->countByStack($boardId, $viewer),
			'byPriority' => $this->cardMapper->countByPriority($boardId, $viewer),
			'byAssignee' => $this->cardAssigneeMapper->countByAssigneeForBoard($boardId, $viewer),
			'byLabel' => $this->cardLabelMapper->countByLabelForBoard($boardId, $viewer),
			'throughput' => $this->bucketByDay($this->cardMapper->doneTimeline($boardId, $windowStart, $now, $viewer)),
			'created' => $this->bucketByDay($this->cardMapper->createdTimeline($boardId, $windowStart, $now, $viewer)),
			'estimateByStack' => $this->estimateByStack($board, $boardId, $viewer),
			'estimateByAssignee' => $this->estimateByAssignee($board, $boardId, $viewer),
			'aging' => ['days' => self::AGING_DAYS, 'count' => $this->cardMapper->agingCount($boardId, $agingCutoff, $viewer)],
			'overdue' => $this->cardMapper->overdueCount($boardId, new \DateTime('@' . $now), $viewer),
			'checklist' => $this->checklistTotals($boardId, $viewer),
			'commentActivity' => $this->commentMapper->countRecentForBoard($boardId, $windowStart, $viewer),
			'velocity' => $this->velocity($completions, $numericScale, $now),
			'cycleTime' => $this->cycleTime($completions, $flowWindowStart),
		];
	}

	/**
	 * The project-analytics DTO, computed over an explicit ACL-resolved card id
	 * set (#3568) rather than a single board. The caller (ProjectStatsController
	 * → ProjectService) has already owner-gated the project AND resolved the card
	 * ids to the owner's readable-board set (one ACL-filtered pass via
	 * {@see \OCA\Kanso\Db\ProjectCardMapper::findCardsInProjectAndBoards}), so a
	 * card on a board the owner cannot READ is never in $cardIds and can never
	 * contribute - there is no board scope here and no cross-board leak. Every
	 * aggregate re-uses the same PHP-side roll-ups as {@see self::boardStats()}
	 * (bucketByDay / velocity / cycleTime / trend), just fed the card-id-set
	 * mapper variants; there is no second stats engine.
	 *
	 * This DTO deliberately DIFFERS from the board one on the board-specific
	 * panels that carry no cross-board meaning:
	 *   - byStack is OMITTED: stacks belong to one board, so a project-wide
	 *     per-stack count would collide unrelated columns from different boards.
	 *   - estimate totals / points velocity are OMITTED: a project may span
	 *     boards on different estimate scales (a t-shirt board's tokens cannot be
	 *     summed with a fibonacci board's), so points are never summed here -
	 *     velocity.pointsPerWeek / pointsTrend are always null and there are no
	 *     estimateBy* panels. Cross-board-meaningful metrics (byPriority,
	 *     byAssignee, byLabel, throughput/created timelines, overdue, aging,
	 *     checklist, comment activity, velocity cards + cycle time) are kept.
	 *
	 * @param int[] $cardIds the owner's ACL-resolved project card ids (empty → all-zero DTO)
	 * @return array{
	 *     byPriority: list<array{priority: int, count: int}>,
	 *     byAssignee: list<array{uid: string, count: int}>,
	 *     byLabel: list<array{boardId: int, labelId: int, count: int}>,
	 *     throughput: list<array{day: string, count: int}>,
	 *     created: list<array{day: string, count: int}>,
	 *     aging: array{days: int, count: int},
	 *     overdue: int,
	 *     checklist: array{total: int, done: int},
	 *     commentActivity: int,
	 *     velocity: array{
	 *         weeks: int,
	 *         windowDays: int,
	 *         cardsPerWeek: float,
	 *         cardsTrend: string,
	 *         pointsPerWeek: null|float,
	 *         pointsTrend: null|string,
	 *         weekly: list<array{week: string, cards: int, points: null|float}>
	 *     },
	 *     cycleTime: array{windowDays: int, sampleSize: int, medianDays: null|float, averageDays: null|float},
	 *     cardCount: int
	 * }
	 * @throws \OCP\DB\Exception
	 */
	public function projectStats(array $cardIds): array {
		$now = time();
		$windowStart = $now - self::WINDOW_DAYS * self::DAY_SECONDS;
		$agingCutoff = $now - self::AGING_DAYS * self::DAY_SECONDS;
		$flowWindowStart = $now - self::FLOW_WEEKS * self::WEEK_SECONDS;
		$flowFetchStart = $now - 2 * self::FLOW_WEEKS * self::WEEK_SECONDS;

		$completions = $this->cardMapper->doneCycleTimesForCards($cardIds, $flowFetchStart, $now);

		return [
			'byPriority' => $this->cardMapper->countByPriorityForCards($cardIds),
			'byAssignee' => $this->cardAssigneeMapper->countByAssigneeForCards($cardIds),
			'byLabel' => $this->cardLabelMapper->countByLabelForCards($cardIds),
			'throughput' => $this->bucketByDay($this->cardMapper->doneTimelineForCards($cardIds, $windowStart, $now)),
			'created' => $this->bucketByDay($this->cardMapper->createdTimelineForCards($cardIds, $windowStart, $now)),
			'aging' => ['days' => self::AGING_DAYS, 'count' => $this->cardMapper->agingCountForCards($cardIds, $agingCutoff)],
			'overdue' => $this->cardMapper->overdueCountForCards($cardIds, new \DateTime('@' . $now)),
			'checklist' => $this->checklistTotalsForCards($cardIds),
			'commentActivity' => $this->commentMapper->countRecentForCards($cardIds, $windowStart),
			// Mixed estimate scales across boards ⇒ points are never summed: pass
			// $numericScale = false so velocity reports cards only (points null).
			'velocity' => $this->velocity($completions, false, $now),
			'cycleTime' => $this->cycleTime($completions, $flowWindowStart),
			'cardCount' => count($cardIds),
		];
	}

	/**
	 * Buckets a flat list of unix timestamps into ascending per-day
	 * {day: "YYYY-MM-DD", count} rows (UTC, sparse: only days with activity).
	 * Kept in PHP so the timeline queries carry no per-dialect date SQL.
	 *
	 * @param int[] $timestamps
	 * @return list<array{day: string, count: int}>
	 */
	private function bucketByDay(array $timestamps): array {
		$counts = [];
		foreach ($timestamps as $ts) {
			$day = gmdate('Y-m-d', $ts);
			$counts[$day] = ($counts[$day] ?? 0) + 1;
		}
		ksort($counts);

		$rows = [];
		foreach ($counts as $day => $count) {
			$rows[] = ['day' => $day, 'count' => $count];
		}
		return $rows;
	}

	/**
	 * Velocity - completions rolled up into fixed 7-day buckets anchored at
	 * $now and walking backwards, over the current FLOW_WEEKS window plus an
	 * equal-length prior window (so a direction can be derived). Reports:
	 *   - weekly:        the current window's weeks, oldest-first, each with a
	 *                    card count and (numeric scale only) a points sum;
	 *   - cardsPerWeek:  the current window's rolling average cards/week;
	 *   - cardsTrend:    up / down / flat vs the prior window's total;
	 *   - pointsPerWeek / pointsTrend: same for estimate points, or null when
	 *                    the board scale is not numeric.
	 *
	 * Bucketing is by elapsed weeks from $now (age // 7d), so the buckets are
	 * stable regardless of calendar week boundaries and need no date SQL. The
	 * window is whole weeks (FLOW_WEEKS), so it aligns exactly with the buckets
	 * and with the fetch span (2 * FLOW_WEEKS) - no completion in range is
	 * dropped. Only numeric estimate tokens contribute points (same guard as the
	 * estimate panels); a card with a non-numeric/absent token still counts
	 * toward cards.
	 *
	 * @param list<array{createdAt: int, doneAt: int, estimate: ?string}> $completions
	 *                                                                                 done cards over the doubled flow look-back (2 * FLOW_WEEKS)
	 * @return array{
	 *     weeks: int,
	 *     windowDays: int,
	 *     cardsPerWeek: float,
	 *     cardsTrend: string,
	 *     pointsPerWeek: null|float,
	 *     pointsTrend: null|string,
	 *     weekly: list<array{week: string, cards: int, points: null|float}>
	 * }
	 */
	private function velocity(array $completions, bool $numericScale, int $now): array {
		$weeksPerWindow = self::FLOW_WEEKS;
		$totalWeeks = 2 * $weeksPerWindow;

		// Per-week-bucket accumulators, index 0 = most recent 7 days.
		$cardsByWeek = array_fill(0, $totalWeeks, 0);
		$pointsByWeek = array_fill(0, $totalWeeks, 0.0);

		foreach ($completions as $c) {
			$age = $now - $c['doneAt'];
			if ($age < 0) {
				$age = 0;
			}
			$bucket = intdiv($age, self::WEEK_SECONDS);
			if ($bucket < 0 || $bucket >= $totalWeeks) {
				// Outside the fetch window (only reachable for a row exactly on the
				// far boundary, since $age >= 0) - ignore defensively.
				continue;
			}
			$cardsByWeek[$bucket]++;
			if ($numericScale && $c['estimate'] !== null && is_numeric($c['estimate'])) {
				$pointsByWeek[$bucket] += (float)$c['estimate'];
			}
		}

		// Current window = the most recent $weeksPerWindow buckets (0..N-1);
		// prior window = the next $weeksPerWindow buckets.
		$currentCards = array_sum(array_slice($cardsByWeek, 0, $weeksPerWindow));
		$priorCards = array_sum(array_slice($cardsByWeek, $weeksPerWindow, $weeksPerWindow));
		$currentPoints = array_sum(array_slice($pointsByWeek, 0, $weeksPerWindow));
		$priorPoints = array_sum(array_slice($pointsByWeek, $weeksPerWindow, $weeksPerWindow));

		// weekly rows for the current window, oldest-first (bucket N-1 → 0), each
		// labelled by its ISO start date so the frontend can axis-label them.
		$weekly = [];
		for ($i = $weeksPerWindow - 1; $i >= 0; $i--) {
			$weekStart = $now - ($i + 1) * self::WEEK_SECONDS;
			$weekly[] = [
				'week' => gmdate('Y-m-d', $weekStart),
				'cards' => $cardsByWeek[$i],
				'points' => $numericScale ? round($pointsByWeek[$i], 2) : null,
			];
		}

		return [
			'weeks' => $weeksPerWindow,
			'windowDays' => $weeksPerWindow * 7,
			'cardsPerWeek' => round((float)$currentCards / (float)$weeksPerWindow, 2),
			'cardsTrend' => $this->trend((float)$currentCards, (float)$priorCards),
			'pointsPerWeek' => $numericScale ? round((float)$currentPoints / (float)$weeksPerWindow, 2) : null,
			'pointsTrend' => $numericScale ? $this->trend((float)$currentPoints, (float)$priorPoints) : null,
			'weekly' => $weekly,
		];
	}

	/**
	 * Direction of a current-period total vs the prior period: `up` / `down` /
	 * `flat`. Flat when the two are within a small relative epsilon (so tiny
	 * float wobble on points totals does not flip the arrow); an exact-zero
	 * prior with any current work reads as `up`, and no change either way reads
	 * `flat`.
	 */
	private function trend(float $current, float $prior): string {
		$epsilon = max(0.5, abs($prior) * 0.05);
		$delta = $current - $prior;
		if ($delta > $epsilon) {
			return 'up';
		}
		if ($delta < -$epsilon) {
			return 'down';
		}
		return 'flat';
	}

	/**
	 * Lead/cycle time - median and average create→done duration (in days) over
	 * the SAME flow window as velocity ($windowStart = now - FLOW_WEEKS weeks),
	 * computed in PHP (dialect-safe: no percentile SQL). Only completions whose
	 * done_at falls inside that window are considered (the input spans the
	 * doubled flow look-back). Negative or zero-length spans are clamped to 0
	 * days. Returns null medians on an empty sample so the frontend can render a
	 * neutral "no data" state.
	 *
	 * @param list<array{createdAt: int, doneAt: int, estimate: ?string}> $completions
	 * @return array{windowDays: int, sampleSize: int, medianDays: null|float, averageDays: null|float}
	 */
	private function cycleTime(array $completions, int $windowStart): array {
		$durations = [];
		foreach ($completions as $c) {
			if ($c['doneAt'] < $windowStart) {
				continue;
			}
			$seconds = $c['doneAt'] - $c['createdAt'];
			if ($seconds < 0) {
				$seconds = 0;
			}
			$durations[] = (float)$seconds / (float)self::DAY_SECONDS;
		}

		$windowDays = self::FLOW_WEEKS * 7;

		$n = count($durations);
		if ($n === 0) {
			return ['windowDays' => $windowDays, 'sampleSize' => 0, 'medianDays' => null, 'averageDays' => null];
		}

		sort($durations);
		$mid = intdiv($n, 2);
		$median = ($n % 2 === 1)
			? $durations[$mid]
			: ($durations[$mid - 1] + $durations[$mid]) / 2.0;
		$average = array_sum($durations) / (float)$n;

		return [
			'windowDays' => $windowDays,
			'sampleSize' => $n,
			'medianDays' => round($median, 1),
			'averageDays' => round($average, 1),
		];
	}

	/**
	 * Per-stack estimate totals, or null when the board's scale is not a
	 * numeric one (the frontend hides the panel). Non-numeric tokens are
	 * skipped defensively; the sum is done in PHP to avoid CAST portability
	 * issues. Stacks whose cards contribute no numeric estimate are omitted.
	 *
	 * @return null|list<array{stackId: int, total: float}>
	 * @throws \OCP\DB\Exception
	 */
	private function estimateByStack(Board $board, int $boardId, ViewerContext $viewer): ?array {
		if (!$this->isNumericScale($board)) {
			return null;
		}

		$totals = [];
		foreach ($this->cardMapper->estimateByStack($boardId, $viewer) as $row) {
			if (!is_numeric($row['estimate'])) {
				continue;
			}
			$stackId = $row['stackId'];
			$totals[$stackId] = ($totals[$stackId] ?? 0.0) + (float)$row['estimate'];
		}

		$rows = [];
		foreach ($totals as $stackId => $total) {
			$rows[] = ['stackId' => $stackId, 'total' => $total];
		}
		return $rows;
	}

	/**
	 * Per-assignee estimate totals, or null when the board's scale is not
	 * numeric. Same numeric-token guard and PHP-side sum as
	 * {@see self::estimateByStack()}.
	 *
	 * @return null|list<array{uid: string, total: float}>
	 * @throws \OCP\DB\Exception
	 */
	private function estimateByAssignee(Board $board, int $boardId, ViewerContext $viewer): ?array {
		if (!$this->isNumericScale($board)) {
			return null;
		}

		$totals = [];
		foreach ($this->cardAssigneeMapper->estimateByAssigneeForBoard($boardId, $viewer) as $row) {
			if (!is_numeric($row['estimate'])) {
				continue;
			}
			$uid = $row['uid'];
			$totals[$uid] = ($totals[$uid] ?? 0.0) + (float)$row['estimate'];
		}

		$rows = [];
		foreach ($totals as $uid => $total) {
			$rows[] = ['uid' => $uid, 'total' => $total];
		}
		return $rows;
	}

	/**
	 * A board scale is "numeric" when it is not `none` and every token of the
	 * scale is a numeric string (fibonacci / linear / hours). The t-shirt scale
	 * is textual, so its estimate panels stay null.
	 */
	private function isNumericScale(Board $board): bool {
		$scale = $board->getEstimateScale() ?? EstimateScale::NONE;
		if ($scale === EstimateScale::NONE) {
			return false;
		}
		$tokens = EstimateScale::SCALES[$scale] ?? [];
		if ($tokens === []) {
			return false;
		}
		foreach ($tokens as $token) {
			if (!is_numeric($token)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Board-wide checklist totals, summed from the per-card progress map
	 * (reuses {@see ChecklistItemMapper::progressByBoard()} - the same fixed
	 * two-query aggregate the board payload already uses).
	 *
	 * @return array{total: int, done: int}
	 * @throws \OCP\DB\Exception
	 */
	private function checklistTotals(int $boardId, ViewerContext $viewer): array {
		$total = 0;
		$done = 0;
		foreach ($this->checklistItemMapper->progressByBoard($boardId, $viewer) as $progress) {
			$total += $progress['total'];
			$done += $progress['done'];
		}
		return ['total' => $total, 'done' => $done];
	}

	/**
	 * Checklist totals over an explicit card id set - the project-analytics twin
	 * of {@see self::checklistTotals()}, summed from
	 * {@see ChecklistItemMapper::progressByCards()}.
	 *
	 * @param int[] $cardIds
	 * @return array{total: int, done: int}
	 * @throws \OCP\DB\Exception
	 */
	private function checklistTotalsForCards(array $cardIds): array {
		$total = 0;
		$done = 0;
		foreach ($this->checklistItemMapper->progressByCards($cardIds) as $progress) {
			$total += $progress['total'];
			$done += $progress['done'];
		}
		return ['total' => $total, 'done' => $done];
	}
}

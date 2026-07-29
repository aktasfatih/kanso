<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

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
 * This is deliberately a plain snapshot: NO burndown/velocity/forecasting is
 * derived (charter non-goal). Timelines are raw daily buckets computed in PHP
 * from unix timestamps so the SQL stays dialect-clean.
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

	private const DAY_SECONDS = 86400;

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
	 *     commentActivity: int
	 * }
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function boardStats(int $boardId): array {
		$now = time();
		$windowStart = $now - self::WINDOW_DAYS * self::DAY_SECONDS;
		$agingCutoff = $now - self::AGING_DAYS * self::DAY_SECONDS;

		$board = $this->boardMapper->find($boardId);

		return [
			'byStack' => $this->cardMapper->countByStack($boardId),
			'byPriority' => $this->cardMapper->countByPriority($boardId),
			'byAssignee' => $this->cardAssigneeMapper->countByAssigneeForBoard($boardId),
			'byLabel' => $this->cardLabelMapper->countByLabelForBoard($boardId),
			'throughput' => $this->bucketByDay($this->cardMapper->doneTimeline($boardId, $windowStart, $now)),
			'created' => $this->bucketByDay($this->cardMapper->createdTimeline($boardId, $windowStart, $now)),
			'estimateByStack' => $this->estimateByStack($board, $boardId),
			'estimateByAssignee' => $this->estimateByAssignee($board, $boardId),
			'aging' => ['days' => self::AGING_DAYS, 'count' => $this->cardMapper->agingCount($boardId, $agingCutoff)],
			'overdue' => $this->cardMapper->overdueCount($boardId, new \DateTime('@' . $now)),
			'checklist' => $this->checklistTotals($boardId),
			'commentActivity' => $this->commentMapper->countRecentForBoard($boardId, $windowStart),
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
	 * Per-stack estimate totals, or null when the board's scale is not a
	 * numeric one (the frontend hides the panel). Non-numeric tokens are
	 * skipped defensively; the sum is done in PHP to avoid CAST portability
	 * issues. Stacks whose cards contribute no numeric estimate are omitted.
	 *
	 * @return null|list<array{stackId: int, total: float}>
	 * @throws \OCP\DB\Exception
	 */
	private function estimateByStack(Board $board, int $boardId): ?array {
		if (!$this->isNumericScale($board)) {
			return null;
		}

		$totals = [];
		foreach ($this->cardMapper->estimateByStack($boardId) as $row) {
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
	private function estimateByAssignee(Board $board, int $boardId): ?array {
		if (!$this->isNumericScale($board)) {
			return null;
		}

		$totals = [];
		foreach ($this->cardAssigneeMapper->estimateByAssigneeForBoard($boardId) as $row) {
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
	private function checklistTotals(int $boardId): array {
		$total = 0;
		$done = 0;
		foreach ($this->checklistItemMapper->progressByBoard($boardId) as $progress) {
			$total += $progress['total'];
			$done += $progress['done'];
		}
		return ['total' => $total, 'done' => $done];
	}
}

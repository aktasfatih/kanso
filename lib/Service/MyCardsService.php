<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\CardMapper;

/**
 * The cross-board "My tasks" feed - every open card assigned to the current
 * user, across all boards they can read. ACL is enforced by restricting the
 * query to the user's readable board set (mirrors {@see ReviewService::findMine}
 * and SearchService); a card on a board the user cannot read is never returned.
 */
class MyCardsService {
	/**
	 * Most rows the feed ever returns. The cap keeps the cross-board query
	 * bounded; it is REPORTED rather than applied silently, so a user with
	 * more assigned cards than this sees "showing the first N" instead of a
	 * truncated list that looks complete (and a "N+" nav badge instead of a
	 * frozen, wrong exact count).
	 */
	public const LIMIT = 200;

	/**
	 * How far back "recently done" reaches (#10061).
	 *
	 * A fortnight: it spans a two-week iteration, so a Monday review still
	 * reaches last week's completions (7 would not), without turning the
	 * section into a month-long archive nobody scrolls (30 would). It is a
	 * constant on purpose - there is no setting for it, because nothing in
	 * the product toggles it and a config surface would outlive the feature.
	 */
	public const RECENT_DONE_WINDOW_DAYS = 14;

	/**
	 * Most recently-completed rows the opt-in section ever returns. Bounds the
	 * query on its second axis: the window alone still has no ceiling for
	 * someone who closes hundreds of cards a fortnight.
	 */
	public const RECENT_DONE_LIMIT = 50;

	public function __construct(
		private BoardService $boardService,
		private CardMapper $cardMapper,
		private BoardAccess $boardAccess,
	) {
	}

	/**
	 * @return array{cards: list<array<string, mixed>>, truncated: bool, limit: int}
	 */
	public function findMine(string $uid): array {
		// Active boards only (#10126): an archived board is shelved, so nothing
		// on it belongs in this feed.
		$boards = $this->boardService->findAllActive($uid);
		$boardIds = array_map(
			static fn ($board): int => $board->getId(),
			$boards
		);
		// Visibility (#3743): assignment grants no visibility - the viewer's
		// per-board roles scope the query like every other read path.
		//
		// LIMIT + 1 is the truncation probe: one extra row is enough to know
		// there IS more, without paying for a second COUNT query on a feed that
		// every client polls.
		$rows = $this->cardMapper->findAssignedInBoards(
			[$uid],
			$boardIds,
			$uid,
			$this->boardAccess->rolesFor($boards, $uid),
			self::LIMIT + 1,
		);

		$truncated = count($rows) > self::LIMIT;

		return [
			'cards' => $truncated ? array_slice($rows, 0, self::LIMIT) : $rows,
			'truncated' => $truncated,
			'limit' => self::LIMIT,
		];
	}

	/**
	 * The user's RECENTLY COMPLETED assigned cards (#10061).
	 *
	 * Its own endpoint, and its own query, because it is OPT-IN: the default
	 * "My tasks" load must keep issuing exactly one query for open work. Every
	 * completed task a person has ever been assigned is unbounded on a
	 * long-lived board, so this is fetched only when the user expands the
	 * section, and is bounded on both axes - a
	 * {@see self::RECENT_DONE_WINDOW_DAYS}-day window and
	 * {@see self::RECENT_DONE_LIMIT} rows.
	 *
	 * Identical ACL to {@see self::findMine()}: the readable board set and the
	 * per-board roles, resolved the same way. No new query engine, no widened
	 * scope - the same readable-board-set + one-query pattern.
	 *
	 * @return array{cards: list<array<string, mixed>>, truncated: bool, limit: int, windowDays: int}
	 */
	public function findMineRecentlyDone(string $uid): array {
		// Active boards only (#10126): an archived board is shelved, so nothing
		// on it belongs in this feed.
		$boards = $this->boardService->findAllActive($uid);
		$boardIds = array_map(
			static fn ($board): int => $board->getId(),
			$boards
		);

		$doneSince = time() - self::RECENT_DONE_WINDOW_DAYS * 86400;

		// LIMIT + 1 probe, as on the open feed: one extra row says there IS
		// more without a second COUNT query, so the section can say "the most
		// recent N" instead of presenting a slice as the whole fortnight.
		$rows = $this->cardMapper->findAssignedDoneSinceInBoards(
			[$uid],
			$boardIds,
			$uid,
			$this->boardAccess->rolesFor($boards, $uid),
			$doneSince,
			self::RECENT_DONE_LIMIT + 1,
		);

		$truncated = count($rows) > self::RECENT_DONE_LIMIT;

		return [
			'cards' => $truncated ? array_slice($rows, 0, self::RECENT_DONE_LIMIT) : $rows,
			'truncated' => $truncated,
			'limit' => self::RECENT_DONE_LIMIT,
			'windowDays' => self::RECENT_DONE_WINDOW_DAYS,
		];
	}
}

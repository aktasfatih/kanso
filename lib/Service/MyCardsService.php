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
		$boards = $this->boardService->findAll($uid);
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
}

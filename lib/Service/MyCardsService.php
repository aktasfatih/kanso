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
	public function __construct(
		private BoardService $boardService,
		private CardMapper $cardMapper,
		private BoardAccess $boardAccess,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findMine(string $uid): array {
		$boards = $this->boardService->findAll($uid);
		$boardIds = array_map(
			static fn ($board): int => $board->getId(),
			$boards
		);
		// Visibility (#3743): assignment grants no visibility - the viewer's
		// per-board roles scope the query like every other read path.
		return $this->cardMapper->findAssignedInBoards(
			[$uid],
			$boardIds,
			$uid,
			$this->boardAccess->rolesFor($boards, $uid),
		);
	}
}

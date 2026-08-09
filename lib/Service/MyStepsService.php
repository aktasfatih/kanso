<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\ChecklistItemMapper;

/**
 * The cross-board "my steps" feed (#3745) - every OPEN checklist step assigned
 * to the current user, across all boards they can read. Mirrors
 * {@see MyCardsService} exactly: ACL is enforced by restricting the query to
 * the user's readable board set, and the card-visibility scope
 * ({@see CardVisibilityScope}, applied inside the mapper with the viewer's
 * per-board role map) hides steps of cards the user may not SEE - being
 * assigned to a step grants no visibility on its card.
 */
class MyStepsService {
	public function __construct(
		private BoardService $boardService,
		private ChecklistItemMapper $checklistItemMapper,
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
		return $this->checklistItemMapper->findOpenAssignedInBoards(
			$uid,
			$boardIds,
			$this->boardAccess->rolesFor($boards, $uid),
		);
	}
}

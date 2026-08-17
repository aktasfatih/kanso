<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\CardMapper;

/**
 * The cross-board feed behind saved "Views" (#3815) - every non-deleted card
 * across all the boards the current user can read, each carrying its board id +
 * title. The server stays filter-AGNOSTIC: it returns the whole readable-set
 * card summaries and the CLIENT applies the saved View's opaque filter predicate
 * and group-by. That keeps the query engine to exactly ONE readable-set path
 * (no new query language) - identical to how {@see MyCardsService::findMine()}
 * and {@see InboxService::findMine()} enforce ACL.
 *
 * ACL is enforced by restricting to the boards {@see BoardService::findAll()}
 * returns (the readable set) and running each board's summary query under the
 * viewer's own per-board {@see \OCA\Kanso\Access\ViewerContext} - so a card on a
 * board the user cannot read, or a card hidden from the viewer's board side
 * (#3743), is never returned. A View run by user A can never surface a card from
 * a board A cannot read (covered by ViewServiceTest's leak-denial test).
 */
class ViewService {
	public function __construct(
		private BoardService $boardService,
		private CardMapper $cardMapper,
		private CardSummaryService $cardSummaryService,
		private BoardAccess $boardAccess,
	) {
	}

	/**
	 * All readable-board card summaries for the user, enriched to the SAME shape
	 * the board payload carries (labelIds / assigneeIds / type / estimate /
	 * duedate / doneAt / startedAt / startDate / waitingOnExternal / checklist /
	 * childProgress / commentCount / priority / boardSeq …) plus boardId +
	 * boardTitle - so the client's reused board filter predicate and group-by can
	 * run over them with no extra request.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findMine(string $uid): array {
		$boards = $this->boardService->findAll($uid);

		$out = [];
		foreach ($boards as $board) {
			$boardId = (int)$board->getId();
			// The viewer's resolved side on THIS board scopes every card row
			// (#3743). findAll() already returned only member boards, so
			// contextFor() resolves without throwing.
			$viewer = $this->boardAccess->contextFor($board, $uid);
			$cards = $this->cardSummaryService->serialize(
				$boardId,
				$this->cardMapper->findSummariesByBoard($boardId, $viewer),
				$viewer,
			);
			$boardTitle = (string)$board->getTitle();
			foreach ($cards as $card) {
				// Carry the board identity so the client can group by board and
				// deep-link back without a per-card board lookup.
				$card['boardId'] = $boardId;
				$card['boardTitle'] = $boardTitle;
				$out[] = $card;
			}
		}

		return $out;
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\LabelMapper;
use OCP\IUserManager;

/**
 * The cross-board card feed behind saved "Views" (#3815). A View is a named,
 * board-agnostic saved filter (persisted per-user in NC config by
 * {@see \OCA\Kanso\Controller\ViewController}); this service supplies the cards
 * it runs over: EVERY card the user can read, across every board they can read,
 * plus the label / assignee catalogs the client filter UI needs to render its
 * facets across that same readable set.
 *
 * ACL is enforced exactly like {@see MyCardsService::findMine} and SearchService:
 * the query is restricted to the user's readable board set and each board's cards
 * are scoped by the viewer's per-board role - a card on a board the user cannot
 * read (or a card hidden from them by visibility #3743) is never returned, and
 * the label/participant catalogs are drawn only from that same readable set.
 *
 * The server is deliberately FILTER-AGNOSTIC: it returns the FULL predicate-ready
 * summary shape (labelIds / assigneeIds / type / estimate / waitingOnExternal /
 * … - the same {@see CardSummaryService} shape the board payload carries) and the
 * CLIENT applies the View's opaque filter predicate. No filter schema lives here,
 * no new query engine.
 *
 * Implementation: iterate the readable boards and reuse the per-board
 * summary+enrichment path ({@see CardMapper::findSummariesByBoard} + the shared
 * {@see CardSummaryService}). This guarantees the predicate has all its fields
 * (a slimmer cross-board query would starve label/assignee/type/estimate/waiting
 * filters) and reuses the already-audited ACL surface rather than opening a new
 * one. Cost is the same per-board enrichment the board view already pays, once
 * per View load (the predicate runs client-side, not per keystroke).
 */
class ViewService {
	public function __construct(
		private BoardService $boardService,
		private CardMapper $cardMapper,
		private BoardAccess $boardAccess,
		private CardSummaryService $cardSummaryService,
		private LabelMapper $labelMapper,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Every readable, non-template card across the user's readable board set, as
	 * full predicate-ready summaries, plus the label and assignee catalogs (over
	 * the SAME readable set) the filter UI renders its facets from. The client
	 * filters the cards with the View's predicate; the server never sees the
	 * filter.
	 *
	 * @return array{
	 *     cards: list<array<string, mixed>>,
	 *     labels: list<array{id: int, title: string, color: ?string}>,
	 *     participants: list<array{uid: string, displayName: string}>
	 * }
	 */
	public function findMine(string $uid): array {
		$boards = $this->boardService->findAll($uid);

		$cards = [];
		$labelsById = [];
		$assigneeUids = [];
		foreach ($boards as $board) {
			$boardId = $board->getId();
			// The viewer's resolved side on THIS board scopes its card rows
			// (#3743), exactly as BoardController::show does after the READ gate.
			$viewer = $this->boardAccess->contextFor($board, $uid);
			$summaries = $this->cardSummaryService->serialize(
				$boardId,
				$this->cardMapper->findSummariesByBoard($boardId, $viewer),
				$viewer,
			);
			$boardTitle = $board->getTitle();
			foreach ($summaries as $summary) {
				// The board-scoped summary omits the board title (redundant on a
				// single board); the cross-board list groups by board, so carry it -
				// mirrors the `boardTitle` My-tasks/Projects already expose.
				$summary['boardTitle'] = $boardTitle;
				$cards[] = $summary;
				foreach ($summary['assigneeIds'] ?? [] as $assigneeUid) {
					$assigneeUids[(string)$assigneeUid] = true;
				}
			}

			// Label catalog for the facet UI: dedup by id across boards. Labels are
			// few per board, so a light per-board fetch over the readable set only.
			foreach ($this->labelMapper->findByBoard($boardId) as $label) {
				$labelsById[$label->getId()] = [
					'id' => $label->getId(),
					'title' => $label->getTitle(),
					'color' => $label->getColor(),
				];
			}
		}

		// Assignee catalog: only the users actually present on the returned cards,
		// resolved to display names once per distinct uid.
		$participants = [];
		foreach (array_keys($assigneeUids) as $assigneeUid) {
			$user = $this->userManager->get($assigneeUid);
			$participants[] = [
				'uid' => $assigneeUid,
				'displayName' => $user !== null ? $user->getDisplayName() : $assigneeUid,
			];
		}
		usort($participants, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));

		return [
			'cards' => $cards,
			'labels' => array_values($labelsById),
			'participants' => $participants,
		];
	}
}

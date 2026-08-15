<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRelationMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;

/**
 * Builds the FULL, predicate-ready card-summary payload for a board - the exact
 * shape {@see \OCA\Kanso\Controller\BoardController::show()} feeds the client
 * (labelIds / assigneeIds / contacts / checklist / waitingOnExternal +
 * waitingSince / childProgress / commentCount / reviewState / blocked). Extracted
 * from BoardController so the same byte-identical enrichment can be reused both by
 * the board payload AND by cross-board surfaces (Views, #3815) WITHOUT a second
 * summary shape to keep in sync.
 *
 * The enrichment maps are board-wide (one query each, no N+1); the per-card
 * lookups just index into them, so passing a subset of a board's cards is safe
 * and cheap. Every card handed in must already have passed the viewer's
 * visibility scope (the callers fetch through {@see CardMapper::findSummariesByBoard()},
 * which applies it in SQL) - this service adds signal, never widens visibility.
 */
class CardSummaryService {
	public function __construct(
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CardContactMapper $cardContactMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CardMapper $cardMapper,
		private CommentMapper $commentMapper,
		private CardReviewMapper $cardReviewMapper,
		private CardRelationMapper $cardRelationMapper,
	) {
	}

	/**
	 * Enrich a board's (already visibility-scoped) card summaries with the same
	 * per-card signal the board payload carries. Producing a BYTE-IDENTICAL card
	 * shape matters: delta-sync upserts and cross-board feeds must be
	 * indistinguishable from a full-board card.
	 *
	 * @param Card[] $cards cards from one board, already scoped to $viewer
	 * @return list<array<string, mixed>>
	 */
	public function serialize(int $boardId, array $cards, ViewerContext $viewer): array {
		$labelIdsByCard = $this->cardLabelMapper->findLabelIdsByBoard($boardId);
		$assigneesByCard = $this->cardAssigneeMapper->findUserIdsByBoard($boardId);
		$contactsByCard = $this->cardContactMapper->findContactsByBoard($boardId);
		$checklistByCard = $this->checklistItemMapper->progressByBoard($boardId, $viewer);
		// Derived "waiting on client" (#3746): cardId => oldest open external
		// step's assigned_at. Presence = waiting; never stored, always computed.
		$waitingByCard = $this->checklistItemMapper->waitingByBoard($boardId, $viewer);
		$childProgressByCard = $this->cardMapper->childProgressByBoard($boardId, $viewer);
		$commentCountByCard = $this->commentMapper->countsByBoard($boardId);
		$reviewStateByCard = $this->cardReviewMapper->reviewStatesByBoard($boardId);
		// Card ids blocked by a not-done card - drives the tile "blocked" badge.
		$blockedIds = array_flip($this->cardRelationMapper->blockedCardIdsByBoard($boardId));

		// array_values so the result is a genuine list (Card[] may be keyed by the
		// mapper); the consumer serializes it as a JSON array.
		return array_values(array_map(
			static fn (Card $card): array => $card->jsonSerializeSummary()
				+ ['labelIds' => $labelIdsByCard[$card->getId()] ?? []]
				+ ['assigneeIds' => $assigneesByCard[$card->getId()] ?? []]
				+ ['contacts' => $contactsByCard[$card->getId()] ?? []]
				+ ['checklist' => $checklistByCard[$card->getId()] ?? ['total' => 0, 'done' => 0]]
				+ ['waitingOnExternal' => \array_key_exists($card->getId(), $waitingByCard)]
				+ ['waitingSince' => $waitingByCard[$card->getId()] ?? null]
				+ ['childProgress' => $childProgressByCard[$card->getId()] ?? ['total' => 0, 'done' => 0]]
				+ ['commentCount' => $commentCountByCard[$card->getId()] ?? 0]
				+ ['reviewState' => $reviewStateByCard[$card->getId()] ?? null]
				+ ['blocked' => isset($blockedIds[$card->getId()])],
			$cards
		));
	}
}

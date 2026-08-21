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
use OCA\Kanso\Db\RecurRuleMapper;

/**
 * Builds board-card SUMMARIES enriched with the same per-card signal every
 * board-scoped surface carries (labelIds / assigneeIds / contacts / checklist /
 * waitingOnExternal + waitingSince / childProgress / commentCount / reviewState
 * / blocked).
 *
 * Extracted verbatim from the old {@see \OCA\Kanso\Controller\BoardController}::serializeCardSummaries
 * so the board payload, the delta-sync upsert and the cross-board Views feed
 * (#3815) all produce a BYTE-IDENTICAL card shape from one place - a patched
 * client-cache entry must be indistinguishable from a freshly fetched one, and
 * Views must group/filter on the exact same fields the board list does.
 *
 * The enrichment maps are board-wide (one query each, no N+1); the per-card
 * lookups just index into them, so passing a subset of a board's cards is safe
 * and cheap. Every enrichment query is viewer-scoped where visibility matters
 * (checklist/waiting/childProgress), so a card hidden from the viewer never
 * enters the result and no hidden card's signal leaks through the maps.
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
		private RecurRuleMapper $recurRuleMapper,
	) {
	}

	/**
	 * @param Card[] $cards the (already visibility-scoped) summary cards of ONE board
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
		// Template card ids with a live (enabled) recurrence rule - drives the
		// tile "recurring" badge. Only the boolean presence ships to the summary;
		// the rrule/rule object stays out of the board payload.
		$recurringIds = array_flip($this->recurRuleMapper->findTemplateCardIdsByBoard($boardId));

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
				+ ['blocked' => isset($blockedIds[$card->getId()])]
				+ ['recurring' => isset($recurringIds[$card->getId()])],
			$cards
		));
	}
}

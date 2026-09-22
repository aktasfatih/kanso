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
use OCA\Kanso\Db\CardRunningTimerMapper;
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
 *
 * Two entry points, ONE row assembler ({@see self::decorate()}):
 * {@see self::serialize()} enriches ONE board under a resolved
 * {@see ViewerContext}, {@see self::serializeForBoards()} enriches a whole
 * BOARD SET under the viewer's per-board role map - the same fixed query count
 * for 30 boards as for one (#10298).
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
		private CardRunningTimerMapper $runningTimerMapper,
	) {
	}

	/**
	 * @param Card[] $cards the (already visibility-scoped) summary cards of ONE board
	 * @return list<array<string, mixed>>
	 */
	public function serialize(int $boardId, array $cards, ViewerContext $viewer): array {
		return $this->decorate($cards, [
			'labelIds' => $this->cardLabelMapper->findLabelIdsByBoard($boardId),
			'assignees' => $this->cardAssigneeMapper->findUserIdsByBoard($boardId),
			'contacts' => $this->cardContactMapper->findContactsByBoard($boardId),
			'checklist' => $this->checklistItemMapper->progressByBoard($boardId, $viewer),
			// Derived overdue-step count (#10696): cardId => open past-due steps.
			// Folded into the emitted `checklist` shape below, so the tile tints
			// its existing badge instead of growing a second one.
			'checklistOverdue' => $this->checklistItemMapper->overdueByBoard($boardId, new \DateTime('@' . time()), $viewer),
			// Derived "waiting on client" (#3746): cardId => oldest open external
			// step's assigned_at. Presence = waiting; never stored, always computed.
			'waiting' => $this->checklistItemMapper->waitingByBoard($boardId, $viewer),
			'childProgress' => $this->cardMapper->childProgressByBoard($boardId, $viewer),
			'commentCount' => $this->commentMapper->countsByBoard($boardId),
			'reviewState' => $this->cardReviewMapper->reviewStatesByBoard($boardId),
			// Card ids blocked by a not-done card - drives the tile "blocked" badge.
			'blocked' => array_flip($this->cardRelationMapper->blockedCardIdsByBoard($boardId)),
			// Template card ids with a live (enabled) recurrence rule - drives the
			// tile "recurring" badge. Only the boolean presence ships to the summary;
			// the rrule/rule object stays out of the board payload.
			'recurring' => array_flip($this->recurRuleMapper->findTemplateCardIdsByBoard($boardId)),
			// Card ids with an active running timer (#73) - drives the tile
			// "timer running" badge. One boolean per card; the timer row stays out.
			'timerRunning' => array_flip($this->runningTimerMapper->findCardIdsByBoard($boardId)),
		]);
	}

	/**
	 * The BOARD-SET twin of {@see self::serialize()} (#10298) - the identical
	 * card shape for cards spanning MANY boards, built from the SAME fixed
	 * number of enrichment queries as one board costs. The cross-board Views
	 * feed used to call serialize() once per readable board, which made its
	 * enrichment O(boards) - 13 queries each - even though every one of these
	 * maps is keyed by a GLOBALLY unique card id and so unions for free.
	 *
	 * Visibility (#3743) is the cross-board mode of the scope: the viewer's
	 * role is applied PER BOARD through $rolesByBoard, so a user who is
	 * internal on one board and external on another gets each board's own
	 * masking - the same per-board answer the per-board loop produced, in one
	 * query. Callers still pass only boards the viewer may read.
	 *
	 * @param int[] $boardIds the boards $cards were read from (the readable set)
	 * @param Card[] $cards the (already visibility-scoped) summary cards across those boards
	 * @param array<int, string> $rolesByBoard {@see \OCA\Kanso\Access\BoardAccess::rolesFor()}
	 * @return list<array<string, mixed>>
	 */
	public function serializeForBoards(array $boardIds, array $cards, string $uid, array $rolesByBoard): array {
		return $this->decorate($cards, [
			'labelIds' => $this->cardLabelMapper->findLabelIdsByBoards($boardIds),
			'assignees' => $this->cardAssigneeMapper->findUserIdsByBoards($boardIds),
			'contacts' => $this->cardContactMapper->findContactsByBoards($boardIds),
			'checklist' => $this->checklistItemMapper->progressByBoards($boardIds, $uid, $rolesByBoard),
			'checklistOverdue' => $this->checklistItemMapper->overdueByBoards($boardIds, new \DateTime('@' . time()), $uid, $rolesByBoard),
			'waiting' => $this->checklistItemMapper->waitingByBoards($boardIds, $uid, $rolesByBoard),
			'childProgress' => $this->cardMapper->childProgressByBoards($boardIds, $uid, $rolesByBoard),
			'commentCount' => $this->commentMapper->countsByBoards($boardIds),
			'reviewState' => $this->cardReviewMapper->reviewStatesByBoards($boardIds),
			'blocked' => array_flip($this->cardRelationMapper->blockedCardIdsByBoards($boardIds)),
			'recurring' => array_flip($this->recurRuleMapper->findTemplateCardIdsByBoards($boardIds)),
			'timerRunning' => array_flip($this->runningTimerMapper->findCardIdsByBoards($boardIds)),
		]);
	}

	/**
	 * The ONE place a summary row is assembled - shared by the board-scoped and
	 * board-set entry points above so the two can not drift into different card
	 * shapes. Every map is keyed by card id; the per-card lookups just index
	 * into them, so passing a subset (or a cross-board union) is safe.
	 *
	 * @param Card[] $cards
	 * @param array{
	 *     labelIds: array<int, int[]>,
	 *     assignees: array<int, string[]>,
	 *     contacts: array<int, list<array{contactUri: string, displayName: string}>>,
	 *     checklist: array<int, array{total: int, done: int}>,
	 *     checklistOverdue: array<int, int>,
	 *     waiting: array<int, ?int>,
	 *     childProgress: array<int, array{total: int, done: int}>,
	 *     commentCount: array<int, int>,
	 *     reviewState: array<int, string>,
	 *     blocked: array<int, int>,
	 *     recurring: array<int, int>,
	 *     timerRunning: array<int, int>,
	 * } $maps
	 * @return list<array<string, mixed>>
	 */
	private function decorate(array $cards, array $maps): array {
		// array_values so the result is a genuine list (Card[] may be keyed by the
		// mapper); the consumer serializes it as a JSON array.
		return array_values(array_map(
			static fn (Card $card): array => $card->jsonSerializeSummary()
				+ ['labelIds' => $maps['labelIds'][$card->getId()] ?? []]
				+ ['assigneeIds' => $maps['assignees'][$card->getId()] ?? []]
				+ ['contacts' => $maps['contacts'][$card->getId()] ?? []]
				// `overdue` rides INSIDE the checklist shape (#10696) rather than as
				// a sibling field: the tile tints the one checklist badge off it, and
				// a nested count can not be mistaken for a card-level due signal.
				+ ['checklist' => ($maps['checklist'][$card->getId()] ?? ['total' => 0, 'done' => 0])
					+ ['overdue' => $maps['checklistOverdue'][$card->getId()] ?? 0]]
				+ ['waitingOnExternal' => \array_key_exists($card->getId(), $maps['waiting'])]
				+ ['waitingSince' => $maps['waiting'][$card->getId()] ?? null]
				+ ['childProgress' => $maps['childProgress'][$card->getId()] ?? ['total' => 0, 'done' => 0]]
				+ ['commentCount' => $maps['commentCount'][$card->getId()] ?? 0]
				+ ['reviewState' => $maps['reviewState'][$card->getId()] ?? null]
				+ ['blocked' => isset($maps['blocked'][$card->getId()])]
				+ ['recurring' => isset($maps['recurring'][$card->getId()])]
				+ ['timerRunning' => isset($maps['timerRunning'][$card->getId()])],
			$cards
		));
	}
}

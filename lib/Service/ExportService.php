<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\AutomationRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Db\StackMapper;

/**
 * Assembles a board's entire live graph into Kanso's own round-trippable
 * export document (distinct from the Deck importer, which reads a foreign
 * schema). The result is a versioned envelope
 *
 *     {"kanso": 1, "exportedAt": <ts>, "board": {...}}
 *
 * carrying EVERYTHING for one board: the board itself, its stacks (with role
 * + wip limit), its live cards (all fields, soft-deleted rows excluded), the
 * card↔label and card↔assignee links, labels, checklist items, comments (with
 * their threading parent, author uid and timestamps), archive rules, recur
 * rules, automation rules and review types.
 *
 * Every entity keeps its ORIGINAL numeric id so {@see ImportService} can
 * rebuild the reference graph; sort keys are emitted verbatim (they are
 * portable lexorank strings). The reader is read-only and gated on board READ
 * by the caller.
 */
class ExportService {
	/**
	 * The envelope format version this build writes and can read back.
	 * v2 added the board's automation rules to the envelope; older (v1)
	 * documents simply carry no automationRules key and still import.
	 */
	public const FORMAT_VERSION = 2;

	public function __construct(
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CommentMapper $commentMapper,
		private ArchiveRuleMapper $archiveRuleMapper,
		private RecurRuleMapper $recurRuleMapper,
		private ReviewTypeMapper $reviewTypeMapper,
		private CardReviewMapper $cardReviewMapper,
		private AutomationRuleMapper $automationRuleMapper,
	) {
	}

	/**
	 * Builds the full export envelope for a board. The board must already be
	 * loaded and READ-authorized by the caller.
	 *
	 * @return array{kanso: int, exportedAt: int, board: array<string, mixed>}
	 * @throws \OCP\DB\Exception
	 */
	public function export(Board $board): array {
		$boardId = $board->getId();

		// One stack fetch drives BOTH the stack list and the per-stack card walk
		// (avoids a second identical query).
		$boardStacks = $this->stackMapper->findByBoard($boardId);

		$stacks = [];
		foreach ($boardStacks as $stack) {
			$stacks[] = [
				'id' => $stack->getId(),
				'title' => $stack->getTitle(),
				'sortKey' => $stack->getSortKey(),
				'archived' => $stack->getArchived(),
				'role' => $stack->getRole(),
				'wipLimit' => $stack->getWipLimit(),
				'color' => $stack->getColor(),
			];
		}

		$labels = [];
		foreach ($this->labelMapper->findByBoard($boardId) as $label) {
			$labels[] = [
				'id' => $label->getId(),
				'title' => $label->getTitle(),
				'color' => $label->getColor(),
			];
		}

		$reviewTypes = [];
		foreach ($this->reviewTypeMapper->findByBoard($boardId) as $type) {
			$reviewTypes[] = [
				'id' => $type->getId(),
				'title' => $type->getTitle(),
				'color' => $type->getColor(),
			];
		}

		// Live cards only: findSummariesByBoard already excludes soft-deleted
		// rows. The summary query omits the description, so re-fetch each card
		// in full for a lossless export.
		$cards = [];
		foreach ($boardStacks as $stack) {
			foreach ($this->cardMapper->findByStack($stack->getId()) as $summary) {
				$card = $this->cardMapper->find($summary->getId());
				$cards[] = $this->serializeCard($card);
			}
		}

		$archiveRules = [];
		foreach ($this->archiveRuleMapper->findByBoard($boardId) as $rule) {
			$archiveRules[] = [
				'id' => $rule->getId(),
				'stackId' => $rule->getStackId(),
				'condition' => $rule->getCondition(),
				'thresholdSeconds' => $rule->getThresholdSeconds(),
				'enabled' => $rule->getEnabled(),
				'createdAt' => $rule->getCreatedAt(),
			];
		}

		$recurRules = [];
		foreach ($this->recurRuleMapper->findByBoard($boardId) as $rule) {
			$recurRules[] = [
				'id' => $rule->getId(),
				'templateCardId' => $rule->getTemplateCardId(),
				'targetStackId' => $rule->getTargetStackId(),
				'mode' => $rule->getMode(),
				'rrule' => $rule->getRrule(),
				'duedatePolicy' => $rule->getDuedatePolicy(),
				'duedateOffsetSeconds' => $rule->getDuedateOffsetSeconds(),
				'skipWhileOpen' => $rule->getSkipWhileOpen(),
				'enabled' => $rule->getEnabled(),
				'owner' => $rule->getOwner(),
				'lastSpawnedAt' => $rule->getLastSpawnedAt(),
				'nextOccurrenceAt' => $rule->getNextOccurrenceAt(),
				'occurrencesSpawned' => $rule->getOccurrencesSpawned(),
				'createdAt' => $rule->getCreatedAt(),
				'timezone' => $rule->getTimezone(),
			];
		}

		$automationRules = [];
		foreach ($this->automationRuleMapper->findByBoard($boardId) as $rule) {
			$automationRules[] = [
				'id' => $rule->getId(),
				'trigger' => $rule->getTrigger(),
				'action' => $rule->getAction(),
				'params' => $rule->paramsArray(),
				'enabled' => $rule->getEnabled(),
				'createdAt' => $rule->getCreatedAt(),
			];
		}

		return [
			'kanso' => self::FORMAT_VERSION,
			'exportedAt' => time(),
			'board' => [
				'title' => $board->getTitle(),
				'color' => $board->getColor(),
				'archived' => $board->getArchived(),
				'estimateScale' => $board->getEstimateScale(),
				'newCardsOnTop' => $board->getNewCardsOnTop() ?? false,
				'stacks' => $stacks,
				'labels' => $labels,
				'reviewTypes' => $reviewTypes,
				'cards' => $cards,
				'archiveRules' => $archiveRules,
				'recurRules' => $recurRules,
				'automationRules' => $automationRules,
			],
		];
	}

	/**
	 * Full serialization of one card plus its per-card children (labels,
	 * assignees, checklist items, comments, reviews).
	 *
	 * @return array<string, mixed>
	 * @throws \OCP\DB\Exception
	 */
	private function serializeCard(Card $card): array {
		$cardId = $card->getId();

		$checklist = [];
		foreach ($this->checklistItemMapper->findByCard($cardId) as $item) {
			$checklist[] = [
				'title' => $item->getTitle(),
				'done' => $item->getDone(),
				'sortKey' => $item->getSortKey(),
				'createdAt' => $item->getCreatedAt(),
			];
		}

		$comments = [];
		foreach ($this->commentMapper->findByCard($cardId) as $comment) {
			$comments[] = [
				'id' => $comment->getId(),
				'parentCommentId' => $comment->getParentCommentId(),
				'author' => $comment->getAuthor(),
				'body' => $comment->getBody(),
				'createdAt' => $comment->getCreatedAt(),
				'editedAt' => $comment->getEditedAt(),
			];
		}

		$reviews = [];
		foreach ($this->cardReviewMapper->findByCard($cardId) as $review) {
			$reviews[] = [
				'reviewer' => $review->getReviewer(),
				'state' => $review->getState(),
				'requestedBy' => $review->getRequestedBy(),
				'createdAt' => $review->getCreatedAt(),
				'reviewTypeId' => $review->getReviewTypeId(),
			];
		}

		return [
			'id' => $cardId,
			'stackId' => $card->getStackId(),
			'title' => $card->getTitle(),
			'description' => $card->getDescription(),
			'sortKey' => $card->getSortKey(),
			'duedate' => $card->getDuedate()?->getTimestamp(),
			'startDate' => $card->getStartDate()?->getTimestamp(),
			'doneAt' => $card->getDoneAt(),
			'startedAt' => $card->getStartedAt(),
			'archived' => $card->getArchived(),
			'allDay' => $card->getAllDay() ?? false,
			'owner' => $card->getOwner(),
			'createdAt' => $card->getCreatedAt(),
			'lastModified' => $card->getLastModified(),
			'parentCardId' => $card->getParentCardId(),
			'priority' => $card->getPriority(),
			'estimate' => $card->getEstimate(),
			'labelIds' => $this->cardLabelMapper->findLabelIdsByCard($cardId),
			'assignees' => $this->cardAssigneeMapper->findUserIdsByCard($cardId),
			'checklist' => $checklist,
			'comments' => $comments,
			'reviews' => $reviews,
		];
	}
}

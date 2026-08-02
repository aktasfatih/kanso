<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\ArchiveRule;
use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReview;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\ReviewType;
use OCA\Kanso\Db\ReviewTypeMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Recreates a whole board graph from a Kanso export document (see
 * {@see ExportService}) into a FRESH board owned by the importer.
 *
 * Everything is inserted with brand-new ids; every internal reference is
 * remapped old→new (stack ids, parent_card_id, parent_comment_id, label ids,
 * card↔label / card↔assignee links, recur template_card_id / target_stack_id,
 * review_type_id on cards' reviews). Sort keys are preserved verbatim (they
 * are portable lexorank strings).
 *
 * Cross-instance identity: uids referenced by assignees and comment authors
 * may not exist on this server. The documented rules are:
 *   - assignees: an unknown uid is DROPPED (the assignment simply does not
 *     survive; the card still imports),
 *   - comment authors: an unknown uid is REMAPPED to the importer, so the
 *     comment text and its threading are never lost.
 * Neither ever fails the import.
 *
 * The whole rebuild runs in ONE transaction (all-or-nothing). Rows are written
 * straight through the mappers, and the board is created via
 * {@see BoardService::create} so its change log + ETag start with a single
 * clean CREATE entry.
 */
class ImportService {
	/**
	 * Hard ceiling on the accepted document size, in bytes. A board export is
	 * plain structured text; anything past this is rejected before parsing to
	 * bound memory. 12 MiB comfortably fits very large boards while stopping a
	 * pathological upload.
	 */
	public const MAX_DOCUMENT_BYTES = 12 * 1024 * 1024;

	public function __construct(
		private BoardService $boardService,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private ChecklistItemMapper $checklistItemMapper,
		private CommentMapper $commentMapper,
		private ReviewTypeMapper $reviewTypeMapper,
		private CardReviewMapper $cardReviewMapper,
		private ArchiveRuleMapper $archiveRuleMapper,
		private RecurRuleMapper $recurRuleMapper,
		private IUserManager $userManager,
		private IDBConnection $db,
	) {
	}

	/**
	 * Imports a Kanso export document into a new board owned by the actor.
	 *
	 * @param string $rawDocument the raw uploaded/pasted JSON export
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 * @throws InvalidInputException on an oversized, malformed or unsupported document
	 */
	public function import(string $rawDocument, string $actorUid): array {
		if (strlen($rawDocument) > self::MAX_DOCUMENT_BYTES) {
			throw new InvalidInputException('The export file is too large to import');
		}

		try {
			$doc = json_decode($rawDocument, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new InvalidInputException('The file is not valid JSON');
		}
		if (!is_array($doc)) {
			throw new InvalidInputException('The file is not a Kanso export');
		}

		$version = $doc['kanso'] ?? null;
		if (!is_int($version)) {
			throw new InvalidInputException('The file is not a Kanso export');
		}
		if ($version > ExportService::FORMAT_VERSION) {
			throw new InvalidInputException(
				'This export was made by a newer version of Kanso and cannot be imported'
			);
		}
		if ($version < 1) {
			throw new InvalidInputException('Unsupported Kanso export version');
		}

		$board = $doc['board'] ?? null;
		if (!is_array($board) || !isset($board['title']) || !is_string($board['title'])) {
			throw new InvalidInputException('The export is missing its board');
		}

		$this->db->beginTransaction();
		try {
			$result = $this->rebuild($board, $actorUid);
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $board
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 */
	private function rebuild(array $board, string $actorUid): array {
		$color = isset($board['color']) && is_string($board['color']) ? $board['color'] : null;
		$newBoard = $this->boardService->create((string)$board['title'], $color, $actorUid);
		$boardId = $newBoard->getId();
		$now = time();

		// Board-level settings the create() call does not take.
		$estimateScale = isset($board['estimateScale']) && is_string($board['estimateScale'])
			? $board['estimateScale'] : null;
		$newCardsOnTop = isset($board['newCardsOnTop']) ? (bool)$board['newCardsOnTop'] : null;
		if ($estimateScale !== null || $newCardsOnTop !== null) {
			$this->boardService->update($boardId, null, null, null, $actorUid, $estimateScale, $newCardsOnTop);
		}

		$labelIdMap = $this->importLabels($board, $boardId);
		$reviewTypeIdMap = $this->importReviewTypes($board, $boardId);
		$stackIdMap = $this->importStacks($board, $boardId);

		[$cardIdMap, $cardCount] = $this->importCards(
			$board, $boardId, $stackIdMap, $labelIdMap, $reviewTypeIdMap, $actorUid, $now
		);

		$this->importArchiveRules($board, $boardId, $stackIdMap, $now);
		$this->importRecurRules($board, $boardId, $stackIdMap, $cardIdMap, $actorUid, $now);

		return [
			'boardId' => $boardId,
			'title' => $newBoard->getTitle(),
			'stacks' => count($stackIdMap),
			'cards' => $cardCount,
			'labels' => count($labelIdMap),
		];
	}

	/**
	 * @param array<string, mixed> $board
	 * @return array<int, int> old label id → new label id
	 */
	private function importLabels(array $board, int $boardId): array {
		$map = [];
		foreach ($this->rows($board, 'labels') as $row) {
			$label = new Label();
			$label->setBoardId($boardId);
			$label->setTitle($this->str($row, 'title', ''));
			$label->setColor($this->nullableStr($row, 'color'));
			$new = $this->labelMapper->insert($label);
			if (isset($row['id'])) {
				$map[(int)$row['id']] = $new->getId();
			}
		}
		return $map;
	}

	/**
	 * @param array<string, mixed> $board
	 * @return array<int, int> old review-type id → new review-type id
	 */
	private function importReviewTypes(array $board, int $boardId): array {
		$map = [];
		foreach ($this->rows($board, 'reviewTypes') as $row) {
			$type = new ReviewType();
			$type->setBoardId($boardId);
			$type->setTitle($this->str($row, 'title', ''));
			$type->setColor($this->nullableStr($row, 'color'));
			$new = $this->reviewTypeMapper->insert($type);
			if (isset($row['id'])) {
				$map[(int)$row['id']] = $new->getId();
			}
		}
		return $map;
	}

	/**
	 * @param array<string, mixed> $board
	 * @return array<int, int> old stack id → new stack id
	 */
	private function importStacks(array $board, int $boardId): array {
		$map = [];
		foreach ($this->rows($board, 'stacks') as $row) {
			$stack = new Stack();
			$stack->setBoardId($boardId);
			$stack->setTitle($this->str($row, 'title', ''));
			$stack->setSortKey($this->str($row, 'sortKey', '1'));
			$stack->setArchived((bool)($row['archived'] ?? false));
			$stack->setRole((int)($row['role'] ?? Stack::ROLE_NONE));
			$stack->setWipLimit(isset($row['wipLimit']) && $row['wipLimit'] !== null ? (int)$row['wipLimit'] : null);
			$stack->setColor($this->nullableStr($row, 'color'));
			$stack->setDeletedAt(0);
			$new = $this->stackMapper->insert($stack);
			if (isset($row['id'])) {
				$map[(int)$row['id']] = $new->getId();
			}
		}
		return $map;
	}

	/**
	 * Inserts cards in TWO passes so parent_card_id can be remapped: pass one
	 * inserts every card with a null parent and records old→new ids; pass two
	 * fills in the remapped parent. Per-card children (labels, assignees,
	 * checklist, comments, reviews) are attached in pass one.
	 *
	 * @param array<string, mixed> $board
	 * @param array<int, int> $stackIdMap
	 * @param array<int, int> $labelIdMap
	 * @param array<int, int> $reviewTypeIdMap
	 * @return array{0: array<int, int>, 1: int} [old card id → new card id, card count]
	 */
	private function importCards(
		array $board,
		int $boardId,
		array $stackIdMap,
		array $labelIdMap,
		array $reviewTypeIdMap,
		string $actorUid,
		int $now,
	): array {
		$cardIdMap = [];
		$parentOf = [];
		$count = 0;

		foreach ($this->rows($board, 'cards') as $row) {
			$oldStackId = isset($row['stackId']) ? (int)$row['stackId'] : null;
			$newStackId = $oldStackId !== null ? ($stackIdMap[$oldStackId] ?? null) : null;
			// A card pointing at a stack that is not in the export is skipped
			// rather than orphaned - it cannot live on the board.
			if ($newStackId === null) {
				continue;
			}

			$card = new Card();
			$card->setBoardId($boardId);
			$card->setStackId($newStackId);
			$card->setTitle($this->str($row, 'title', ''));
			$card->setDescription($this->nullableStr($row, 'description'));
			$card->setSortKey($this->str($row, 'sortKey', '1'));
			$card->setDuedate($this->tsToDate($row['duedate'] ?? null));
			$card->setStartDate($this->tsToDate($row['startDate'] ?? null));
			$card->setDoneAt((int)($row['doneAt'] ?? 0));
			$card->setStartedAt((int)($row['startedAt'] ?? 0));
			$card->setArchived((bool)($row['archived'] ?? false));
			$card->setAllDay((bool)($row['allDay'] ?? false));
			// Preserve the recorded card owner when they still exist here,
			// otherwise the importer takes ownership.
			$owner = $this->nullableStr($row, 'owner');
			$card->setOwner($owner !== null && $this->userManager->userExists($owner) ? $owner : $actorUid);
			$card->setCreatedAt((int)($row['createdAt'] ?? $now));
			$card->setLastModified((int)($row['lastModified'] ?? $now));
			$card->setDeletedAt(0);
			$card->setParentCardId(null);
			$card->setPriority((int)($row['priority'] ?? 0));
			$card->setEstimate($this->nullableStr($row, 'estimate'));
			$new = $this->cardMapper->insert($card);
			$count++;

			if (isset($row['id'])) {
				$oldId = (int)$row['id'];
				$cardIdMap[$oldId] = $new->getId();
				if (isset($row['parentCardId']) && $row['parentCardId'] !== null) {
					$parentOf[$oldId] = (int)$row['parentCardId'];
				}
			}

			$this->attachLabels($row, $new->getId(), $labelIdMap);
			$this->attachAssignees($row, $new->getId());
			$this->attachChecklist($row, $new->getId(), $now);
			$this->attachComments($row, $new->getId(), $actorUid);
			$this->attachReviews($row, $new->getId(), $reviewTypeIdMap, $actorUid, $now);
		}

		// Pass two: remap parents now that every old→new card id is known.
		foreach ($parentOf as $oldId => $oldParentId) {
			$newParentId = $cardIdMap[$oldParentId] ?? null;
			if ($newParentId === null) {
				continue;
			}
			$card = $this->cardMapper->find($cardIdMap[$oldId]);
			$card->setParentCardId($newParentId);
			$this->cardMapper->update($card);
		}

		return [$cardIdMap, $count];
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<int, int> $labelIdMap
	 */
	private function attachLabels(array $row, int $newCardId, array $labelIdMap): void {
		foreach ((array)($row['labelIds'] ?? []) as $oldLabelId) {
			$newLabelId = $labelIdMap[(int)$oldLabelId] ?? null;
			if ($newLabelId !== null) {
				$this->cardLabelMapper->insertAssignment($newCardId, $newLabelId);
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function attachAssignees(array $row, int $newCardId): void {
		foreach ((array)($row['assignees'] ?? []) as $uid) {
			// Unknown uid → drop the assignment (documented rule).
			if (is_string($uid) && $this->userManager->userExists($uid)) {
				$this->cardAssigneeMapper->insertAssignment($newCardId, $uid);
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function attachChecklist(array $row, int $newCardId, int $now): void {
		foreach ((array)($row['checklist'] ?? []) as $item) {
			if (!is_array($item)) {
				continue;
			}
			$entity = new ChecklistItem();
			$entity->setCardId($newCardId);
			$entity->setTitle($this->str($item, 'title', ''));
			$entity->setDone((bool)($item['done'] ?? false));
			$entity->setSortKey($this->str($item, 'sortKey', '1'));
			$entity->setCreatedAt((int)($item['createdAt'] ?? $now));
			$this->checklistItemMapper->insert($entity);
		}
	}

	/**
	 * Comments are inserted top-level-first so replies can remap their parent.
	 * An unknown author uid is remapped to the importer (never dropped).
	 *
	 * @param array<string, mixed> $row
	 */
	private function attachComments(array $row, int $newCardId, string $actorUid): void {
		$rows = [];
		foreach ((array)($row['comments'] ?? []) as $c) {
			if (is_array($c)) {
				$rows[] = $c;
			}
		}
		// Top-level comments (no parent) first, so a reply's parent already has
		// a new id by the time it is inserted.
		usort($rows, static function (array $a, array $b): int {
			$ap = ($a['parentCommentId'] ?? null) === null ? 0 : 1;
			$bp = ($b['parentCommentId'] ?? null) === null ? 0 : 1;
			return $ap <=> $bp;
		});

		$commentIdMap = [];
		foreach ($rows as $c) {
			$author = $this->nullableStr($c, 'author');
			if ($author === null || !$this->userManager->userExists($author)) {
				$author = $actorUid;
			}
			$comment = new Comment();
			$comment->setCardId($newCardId);
			$comment->setAuthor($author);
			$comment->setBody($this->str($c, 'body', ''));
			$comment->setCreatedAt((int)($c['createdAt'] ?? time()));
			$comment->setEditedAt((int)($c['editedAt'] ?? 0));
			$comment->setDeletedAt(0);

			$oldParent = $c['parentCommentId'] ?? null;
			$comment->setParentCommentId($oldParent !== null ? ($commentIdMap[(int)$oldParent] ?? null) : null);

			$new = $this->commentMapper->insert($comment);
			if (isset($c['id'])) {
				$commentIdMap[(int)$c['id']] = $new->getId();
			}
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<int, int> $reviewTypeIdMap
	 */
	private function attachReviews(array $row, int $newCardId, array $reviewTypeIdMap, string $actorUid, int $now): void {
		foreach ((array)($row['reviews'] ?? []) as $r) {
			if (!is_array($r)) {
				continue;
			}
			$reviewer = $this->nullableStr($r, 'reviewer');
			// A review targets a specific reviewer; if that uid is gone here the
			// request is meaningless, so drop it (parallels the assignee rule).
			if ($reviewer === null || !$this->userManager->userExists($reviewer)) {
				continue;
			}
			$requestedBy = $this->nullableStr($r, 'requestedBy');
			if ($requestedBy === null || !$this->userManager->userExists($requestedBy)) {
				$requestedBy = $actorUid;
			}
			$oldType = $r['reviewTypeId'] ?? null;
			$newType = $oldType !== null ? ($reviewTypeIdMap[(int)$oldType] ?? null) : null;

			$review = new CardReview();
			$review->setCardId($newCardId);
			$review->setReviewer($reviewer);
			$review->setState($this->str($r, 'state', CardReview::STATE_PENDING));
			$review->setRequestedBy($requestedBy);
			$review->setCreatedAt((int)($r['createdAt'] ?? $now));
			$review->setReviewTypeId($newType);
			$this->cardReviewMapper->insert($review);
		}
	}

	/**
	 * @param array<string, mixed> $board
	 * @param array<int, int> $stackIdMap
	 */
	private function importArchiveRules(array $board, int $boardId, array $stackIdMap, int $now): void {
		foreach ($this->rows($board, 'archiveRules') as $row) {
			$oldStackId = $row['stackId'] ?? null;
			$newStackId = $oldStackId !== null ? ($stackIdMap[(int)$oldStackId] ?? null) : null;
			// A stack-scoped rule whose stack did not survive is dropped; a
			// whole-board rule (null stack) always imports.
			if ($oldStackId !== null && $newStackId === null) {
				continue;
			}
			$rule = new ArchiveRule();
			$rule->setBoardId($boardId);
			$rule->setStackId($newStackId);
			$rule->setCondition((int)($row['condition'] ?? ArchiveRule::CONDITION_DONE_FOR));
			$rule->setThresholdSeconds((int)($row['thresholdSeconds'] ?? 0));
			$rule->setEnabled((bool)($row['enabled'] ?? false));
			$rule->setCreatedAt((int)($row['createdAt'] ?? $now));
			$this->archiveRuleMapper->insert($rule);
		}
	}

	/**
	 * @param array<string, mixed> $board
	 * @param array<int, int> $stackIdMap
	 * @param array<int, int> $cardIdMap
	 */
	private function importRecurRules(array $board, int $boardId, array $stackIdMap, array $cardIdMap, string $actorUid, int $now): void {
		foreach ($this->rows($board, 'recurRules') as $row) {
			$newTemplateId = isset($row['templateCardId']) ? ($cardIdMap[(int)$row['templateCardId']] ?? null) : null;
			$newTargetId = isset($row['targetStackId']) ? ($stackIdMap[(int)$row['targetStackId']] ?? null) : null;
			// A recur rule with no surviving template card or target stack has
			// nothing to spawn from/into - drop it.
			if ($newTemplateId === null || $newTargetId === null) {
				continue;
			}
			$owner = $this->nullableStr($row, 'owner');
			if ($owner === null || !$this->userManager->userExists($owner)) {
				$owner = $actorUid;
			}
			$rule = new RecurRule();
			$rule->setBoardId($boardId);
			$rule->setTemplateCardId($newTemplateId);
			$rule->setTargetStackId($newTargetId);
			$rule->setMode((int)($row['mode'] ?? RecurRule::MODE_CLONE));
			$rule->setRrule($this->str($row, 'rrule', ''));
			$rule->setDuedatePolicy((int)($row['duedatePolicy'] ?? RecurRule::POLICY_AT_OCCURRENCE));
			$rule->setDuedateOffsetSeconds((int)($row['duedateOffsetSeconds'] ?? 0));
			$rule->setSkipWhileOpen((bool)($row['skipWhileOpen'] ?? false));
			$rule->setEnabled((bool)($row['enabled'] ?? false));
			$rule->setOwner($owner);
			$rule->setLastSpawnedAt((int)($row['lastSpawnedAt'] ?? 0));
			$rule->setNextOccurrenceAt((int)($row['nextOccurrenceAt'] ?? 0));
			$rule->setOccurrencesSpawned((int)($row['occurrencesSpawned'] ?? 0));
			$rule->setCreatedAt((int)($row['createdAt'] ?? $now));
			$rule->setTimezone($this->nullableStr($row, 'timezone'));
			$this->recurRuleMapper->insert($rule);
		}
	}

	// ── small helpers ─────────────────────────────────────────────────────────

	/**
	 * The list under $board[$key] as an array of associative rows, ignoring
	 * anything that is not itself an array.
	 *
	 * @param array<string, mixed> $board
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $board, string $key): array {
		$out = [];
		foreach ((array)($board[$key] ?? []) as $row) {
			if (is_array($row)) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function str(array $row, string $key, string $default): string {
		return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : $default;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function nullableStr(array $row, string $key): ?string {
		return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : null;
	}

	private function tsToDate(mixed $ts): ?\DateTime {
		if ($ts === null || !is_int($ts)) {
			return null;
		}
		return (new \DateTime())->setTimestamp($ts);
	}
}

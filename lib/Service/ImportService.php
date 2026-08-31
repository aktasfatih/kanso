<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\ArchiveRule;
use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\AutomationRule;
use OCA\Kanso\Db\AutomationRuleMapper;
use OCA\Kanso\Db\Board;
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
use Psr\Log\LoggerInterface;

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
	 * plain structured text; anything past this is rejected before parsing. The
	 * cap exists to bound memory: unlike the CSV importer this decodes the whole
	 * export with json_decode (the graph must be resolved as a whole to remap ids),
	 * so the decoded structure - not just the raw bytes - has to fit. The rows are
	 * then inserted one at a time, so 32 MiB comfortably fits tens of thousands of
	 * cards while keeping the decode well within a normal PHP memory limit.
	 */
	public const MAX_DOCUMENT_BYTES = 32 * 1024 * 1024;

	public function __construct(
		private BoardService $boardService,
		private ExportService $exportService,
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
		private AutomationRuleMapper $automationRuleMapper,
		private IUserManager $userManager,
		private IDBConnection $db,
		private BoardAccess $boardAccess,
		private RecurrenceService $recurrenceService,
		private LoggerInterface $logger,
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

		return $this->rebuildInTransaction($board, $actorUid);
	}

	/**
	 * Duplicates an existing board (already READ-authorized by the caller) into a
	 * FRESH board owned by the actor, reusing the export→rebuild machinery: the
	 * source board's live graph is assembled in-process via {@see ExportService}
	 * and fed straight to the same transactional rebuild (no JSON round-trip).
	 *
	 * The copy's title is "<original> (copy)". When $withCards is false the card
	 * graph is stripped, producing a structural-only clone (stacks, roles,
	 * labels, review types, archive/automation rules); recur rules then self-drop
	 * for lack of a surviving template card, which is correct for a template.
	 *
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 * @throws \OCP\DB\Exception
	 */
	public function duplicate(Board $source, string $actorUid, bool $withCards): array {
		// The duplicate carries only cards the DUPLICATING viewer can see
		// (#3743) - same scoped export the download endpoint uses.
		$doc = $this->exportService->export(
			$source,
			$this->boardAccess->contextFor($source, $actorUid),
		);
		$board = $doc['board'];
		$board['title'] = $this->copyTitle($source->getTitle());
		// A copy always starts un-archived, whatever the source's state.
		$board['archived'] = false;
		if (!$withCards) {
			// Structural-only clone: drop the card graph. Recur rules, which point
			// at a template card, are dropped downstream once no card survives.
			$board['cards'] = [];
		}

		return $this->rebuildInTransaction($board, $actorUid);
	}

	/**
	 * Max board-title length {@see BoardService} accepts. Kept in sync here so a
	 * duplicate of an already-maximal title still validates once the " (copy)"
	 * suffix is appended (the base is truncated to make room).
	 */
	private const MAX_TITLE_LENGTH = 100;

	/** The " (copy)" suffix used to name a duplicated board. */
	private const COPY_SUFFIX = ' (copy)';

	/**
	 * "<title> (copy)", truncating the base so the result never overflows the
	 * board-title limit (which would otherwise fail the whole duplicate).
	 */
	private function copyTitle(string $title): string {
		$budget = self::MAX_TITLE_LENGTH - mb_strlen(self::COPY_SUFFIX);
		if (mb_strlen($title) > $budget) {
			$title = mb_substr($title, 0, $budget);
		}
		return $title . self::COPY_SUFFIX;
	}

	/**
	 * Runs {@see rebuild} inside a single all-or-nothing transaction. Shared by
	 * the document import path and the in-process duplicate path.
	 *
	 * @param array<string, mixed> $board
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 */
	private function rebuildInTransaction(array $board, string $actorUid): array {
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
		$this->importAutomationRules($board, $boardId, $labelIdMap, $now);

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

		// Assign human-id numbers locally: seed once from the board's next value
		// (1 for the freshly-created import target) and increment per inserted
		// card - a single query, never one per card. Import runs single-threaded
		// so no unique-index contention; the (board_id, board_seq) index still
		// guards it. Cards are numbered in export/iteration order.
		$nextBoardSeq = $this->cardMapper->nextBoardSeq($boardId);

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
			// Visibility round-trip (#3743): preserve a narrowed visibility so
			// a duplicate/import never silently widens a card to 'public'.
			// Unknown/absent values fall back to the open default (documents
			// predating the field). The creator SIDE is NOT preserved: the
			// importer owns the fresh board and is therefore its provider
			// ('internal') side, and the new board has no ACL yet - keeping a
			// foreign 'external' side would only hide an imported internal
			// card from the very person who imported it. Private cards keep
			// their owner (kept above when the uid exists), so a restored
			// backup still hides them from everyone else.
			$visibility = $this->nullableStr($row, 'visibility');
			$card->setVisibility(
				in_array($visibility, CardVisibilityScope::VISIBILITIES, true)
					? $visibility
					: CardVisibilityScope::VISIBILITY_PUBLIC,
			);
			$card->setCreatorRole(ViewerContext::ROLE_INTERNAL);
			$card->setBoardSeq($nextBoardSeq);
			$nextBoardSeq++;
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
	 * Clone-path policy for rich steps (#3745): the exported `dueDate` (unix
	 * timestamp) is KEPT; any assignee / frozen role / done_at in the document
	 * is deliberately IGNORED - the import lands on a board with its own
	 * membership, so steps arrive unassigned and unstamped.
	 *
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
			if (is_numeric($item['dueDate'] ?? null)) {
				$entity->setDueDate((new \DateTime('now', new \DateTimeZone('UTC')))->setTimestamp((int)$item['dueDate']));
			}
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
			// review_type_id is NOT NULL (Version001600); 0 = the implicit
			// single-stage review, mirroring CardReviewMapper::insertRequest.
			$review->setReviewTypeId($newType ?? 0);
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
			$rrule = $this->str($row, 'rrule', '');
			// Every other write path parse-validates the RRULE before storing it
			// (see RecurrenceService::create/update); import must not be the one
			// hole. A rule we cannot parse spawns nothing and disables itself on
			// the first cron pass anyway, so drop it now - silently, like the
			// sibling drops above.
			try {
				$this->recurrenceService->computeNextOccurrence($rrule, $now, $now);
			} catch (InvalidInputException) {
				$this->logger->warning(
					'Kanso import: dropped a repeat rule whose recurrence rule could not be parsed',
					['rrule' => $rrule],
				);
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
			$rule->setRrule($rrule);
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

	/**
	 * Automation rules are per-board (trigger→action) with a small params blob.
	 * The only id inside params is `label` (add_label action), which is remapped
	 * old→new; a rule whose label did not survive is dropped rather than left
	 * pointing at a foreign label. A `reviewer` uid (request_review action) that
	 * no longer exists here also drops the rule, mirroring the review rule at
	 * {@see attachReviews}. `role` is a portable stack-role constant, kept as-is.
	 *
	 * @param array<string, mixed> $board
	 * @param array<int, int> $labelIdMap
	 */
	private function importAutomationRules(array $board, int $boardId, array $labelIdMap, int $now): void {
		foreach ($this->rows($board, 'automationRules') as $row) {
			$action = $this->str($row, 'action', '');
			$params = is_array($row['params'] ?? null) ? $row['params'] : [];

			if ($action === AutomationRule::ACTION_ADD_LABEL) {
				$oldLabelId = isset($params['label']) ? (int)$params['label'] : 0;
				$newLabelId = $labelIdMap[$oldLabelId] ?? null;
				// The rule adds a label that did not survive the copy - meaningless.
				if ($newLabelId === null) {
					continue;
				}
				$params['label'] = $newLabelId;
			} elseif ($action === AutomationRule::ACTION_REQUEST_REVIEW) {
				$reviewer = isset($params['reviewer']) && is_string($params['reviewer']) ? $params['reviewer'] : '';
				// The rule requests a reviewer who does not exist here - drop it,
				// parallel to the per-card review rule.
				if ($reviewer === '' || !$this->userManager->userExists($reviewer)) {
					continue;
				}
			} else {
				// Unknown action - skip rather than store a rule the engine can't run.
				continue;
			}

			$rule = new AutomationRule();
			$rule->setBoardId($boardId);
			$rule->setTrigger($this->str($row, 'trigger', AutomationRule::TRIGGER_CARD_ENTERED_ROLE));
			$rule->setAction($action);
			$rule->setParams((string)json_encode($params));
			$rule->setEnabled((bool)($row['enabled'] ?? false));
			$rule->setCreatedAt((int)($row['createdAt'] ?? $now));
			$this->automationRuleMapper->insert($rule);
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

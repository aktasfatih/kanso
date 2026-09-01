<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\AutomationRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardAttachmentMapper;
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
 * their threading parent, author uid and timestamps), attachment manifests,
 * archive rules, recur rules, automation rules and review types.
 *
 * Every entity keeps its ORIGINAL numeric id so {@see ImportService} can
 * rebuild the reference graph; sort keys are emitted verbatim (they are
 * portable lexorank strings). The reader is read-only and gated on board READ
 * by the caller.
 *
 * This class produces the DOCUMENT only. Attachment BYTES are carried
 * alongside it by {@see BoardArchiveService}, which packs this envelope as
 * `board.json` plus one archive entry per manifest path.
 */
class ExportService {
	/**
	 * The envelope format version this build writes and can read back.
	 * v2 added the board's automation rules to the envelope; older (v1)
	 * documents simply carry no automationRules key and still import.
	 * v3 added the per-card attachment manifest and moved the delivered
	 * artifact from a bare .json document to a .zip carrying that document
	 * plus the attachment bytes. A v2 document is a strict subset of a v3
	 * one, so v1/v2 archives keep importing untouched.
	 */
	public const FORMAT_VERSION = 3;

	/** Root directory inside a v3 archive holding the attachment objects. */
	public const ATTACHMENT_DIR = 'attachments';

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
		private CardAttachmentMapper $cardAttachmentMapper,
	) {
	}

	/**
	 * Builds the full export envelope for a board. The board must already be
	 * loaded and READ-authorized by the caller.
	 *
	 * Visibility (#3743): the export carries ONLY cards the exporting viewer
	 * can see - a READ-gated export would otherwise dump every internal/
	 * private card (with descriptions and comments) to the other side. The
	 * per-card children (comments, checklist, reviews) hang off the surviving
	 * card set, so a hidden card's discussion never rides along either.
	 *
	 * $viewer = null is the SYSTEM scope: the FULL card set, hidden cards
	 * included. It exists for exactly one caller - the admin backup cron
	 * ({@see BackupService}), whose output lands in an admin-controlled
	 * folder, never in an HTTP response. Every user-facing caller MUST pass
	 * the requesting viewer; the parameter has no default so that choice is
	 * always explicit at the call site.
	 *
	 * Attachments follow that same card set with no gate of their own: the
	 * manifest is built inside {@see self::serializeCard()}, which only ever
	 * runs for cards that survived the filter. So a viewer-scoped export
	 * carries only the files of cards the viewer can see, and the SYSTEM-scope
	 * backup deliberately carries the files of hidden cards too - a backup that
	 * silently dropped them would not restore the instance.
	 *
	 * @return array{kanso: int, exportedAt: int, board: array<string, mixed>}
	 * @throws \OCP\DB\Exception
	 */
	public function export(Board $board, ?ViewerContext $viewer): array {
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

		// Live, viewer-visible cards in ONE full-row query (description
		// included), grouped back into the stack walk so per-stack card order
		// matches the old per-stack reads byte-for-byte.
		$byStack = [];
		foreach ($this->cardMapper->findExportableByBoard($boardId, $viewer) as $card) {
			$byStack[$card->getStackId()][] = $card;
		}
		$cards = [];
		foreach ($boardStacks as $stack) {
			foreach ($byStack[$stack->getId()] ?? [] as $card) {
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
			// Clone-path policy for rich steps (#3745): the due date round-trips
			// (unix timestamp, like the card dates above); assignee, frozen role
			// and done_at deliberately do NOT - an import lands on a board with
			// its own membership, so steps arrive unassigned and unstamped.
			$checklist[] = [
				'title' => $item->getTitle(),
				'done' => $item->getDone(),
				'sortKey' => $item->getSortKey(),
				'createdAt' => $item->getCreatedAt(),
				'dueDate' => $item->getDueDate()?->getTimestamp(),
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
			// Visibility (#3741/#3743) round-trips so a duplicate/import can
			// never silently widen a card back to 'public'.
			'visibility' => $card->getVisibility() ?? CardVisibilityScope::VISIBILITY_PUBLIC,
			'creatorRole' => $card->getCreatorRole() ?? ViewerContext::ROLE_INTERNAL,
			'labelIds' => $this->cardLabelMapper->findLabelIdsByCard($cardId),
			'assignees' => $this->cardAssigneeMapper->findUserIdsByCard($cardId),
			'checklist' => $checklist,
			'comments' => $comments,
			'reviews' => $reviews,
			'attachments' => $this->serializeAttachments($cardId),
		];
	}

	/**
	 * The card's attachment MANIFEST: what the archive carries for this card,
	 * and WHERE - as an archive-local path, never a server path.
	 *
	 * `storage_key` (the server-generated random object name the bytes actually
	 * live under) is DELIBERATELY absent, exactly as it is withheld from every
	 * API response ({@see \OCA\Kanso\Db\CardAttachment::jsonSerialize()}). The
	 * entry path is derived from the attachment's already-public id instead, so
	 * a reader can find the bytes in the archive without ever learning where the
	 * server keeps them. Nothing downstream needs the key: the archive writer
	 * re-reads it straight from the DB.
	 *
	 * Visibility: this runs ONLY for cards that already survived the viewer's
	 * card-set filter (#3743), so a card the exporter cannot see contributes no
	 * manifest entry and therefore no archive entry. Attachment visibility IS
	 * card visibility, by construction rather than by a second check that could
	 * drift.
	 *
	 * @return list<array<string, mixed>>
	 * @throws \OCP\DB\Exception
	 */
	private function serializeAttachments(int $cardId): array {
		$attachments = [];
		foreach ($this->cardAttachmentMapper->findByCard($cardId) as $attachment) {
			$id = (int)$attachment->getId();
			$attachments[] = [
				'id' => $id,
				'filename' => $attachment->getFilename(),
				'mime' => $attachment->getMime(),
				'size' => $attachment->getSize(),
				'uploadedBy' => $attachment->getUploadedBy(),
				'createdAt' => $attachment->getCreatedAt(),
				'path' => self::attachmentPath($id, (string)$attachment->getFilename()),
			];
		}
		return $attachments;
	}

	/**
	 * The archive-local path of one attachment: `attachments/<id>/<filename>`.
	 * The per-attachment directory keeps two identically-named files apart
	 * without hashing or renaming them, and the id is the attachment's public
	 * id - never its storage key.
	 *
	 * The leaf name is re-sanitized here even though it was already sanitized on
	 * the way in: an archive entry name is a PATH to whoever extracts it, so it
	 * must not be able to escape its directory. {@see AttachmentSanitizer} drops
	 * every separator but leaves "." and ".." intact (they are legal basenames),
	 * and either would climb a level in a naive extractor - so both are replaced.
	 */
	public static function attachmentPath(int $attachmentId, string $filename): string {
		$name = AttachmentSanitizer::filename($filename);
		if ($name === '.' || $name === '..') {
			$name = 'attachment';
		}
		return self::ATTACHMENT_DIR . '/' . $attachmentId . '/' . $name;
	}
}

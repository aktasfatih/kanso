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
use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Db\CardAttachmentMapper;
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
use OCP\Files\IAppData;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
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
 *
 * ## Attachments (the v3 archive)
 *
 * An export is a .zip - `board.json` plus one entry per card attachment - so
 * import accepts that archive and closes the round trip: the document restores
 * the graph as ever, and each manifest entry's bytes are written into Kanso's
 * app-data under a FRESH server-generated storage key, exactly as an upload
 * does. A bare v1/v2 JSON document (and a v3 archive carrying no attachments)
 * still imports untouched.
 *
 * The archive is read through {@see ImportArchiveReader}, which owns the
 * hardening: an entry name is only ever a lookup key into that reader's
 * validated index, never a path. App-data writes are not covered by the DB
 * transaction, so every object written is tracked and best-effort deleted if the
 * import throws after it landed (mirroring {@see DeckImportService}).
 */
class ImportService {
	/**
	 * Hard ceiling on the accepted DOCUMENT size, in bytes - `board.json`, and
	 * nothing else. A board export is plain structured text; anything past this
	 * is rejected before parsing. The cap exists to bound memory: unlike the CSV
	 * importer this decodes the whole export with json_decode (the graph must be
	 * resolved as a whole to remap ids), so the decoded structure - not just the
	 * raw bytes - has to fit. The rows are then inserted one at a time, so 32 MiB
	 * comfortably fits tens of thousands of cards while keeping the decode well
	 * within a normal PHP memory limit.
	 *
	 * This is deliberately far below {@see AttachmentSanitizer::MAX_SIZE} (100
	 * MiB, one attachment) and {@see ImportArchiveReader::MAX_TOTAL_BYTES} (the
	 * whole archive): the three cap different things. Only the document is
	 * decoded whole into memory; attachment bytes are streamed to disk in chunks
	 * and never buffered, so they are bounded by storage, not by the decoder.
	 */
	public const MAX_DOCUMENT_BYTES = 32 * 1024 * 1024;

	/** Per-card app-data subfolder holding that card's attachment objects. */
	private const FOLDER_PREFIX = 'card-';

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
		private CardAttachmentMapper $cardAttachmentMapper,
		private IUserManager $userManager,
		private IDBConnection $db,
		private BoardAccess $boardAccess,
		private RecurrenceService $recurrenceService,
		private IAppData $appData,
		private ISecureRandom $secureRandom,
		private ITempManager $tempManager,
		private IMimeTypeDetector $mimeTypeDetector,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Imports an UPLOADED Kanso export into a new board owned by the actor -
	 * the endpoint's main path, and the one that closes the round trip.
	 *
	 * Both shapes are accepted, decided by the file's own leading bytes rather
	 * than by its name or its declared MIME:
	 *  - a v3 ARCHIVE (.zip): `board.json` plus the attachment bytes,
	 *  - a bare JSON DOCUMENT: every v1/v2 export anyone already downloaded.
	 *
	 * The $upload array is the `$_FILES`-shaped entry from
	 * {@see \OCP\IRequest::getUploadedFile()}: keys name, type, size, tmp_name,
	 * error.
	 *
	 * @param array{name?: string, type?: string, size?: int, tmp_name?: string, error?: int}|null $upload
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 * @throws InvalidInputException if the upload is missing, errored, empty, oversized or not a Kanso export
	 */
	public function importUploadedFile(?array $upload, string $actorUid): array {
		if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			$error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
			if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
				throw new InvalidInputException('The export file is too large to import');
			}
			throw new InvalidInputException('No export file uploaded');
		}

		$tmpName = $upload['tmp_name'] ?? '';
		if ($tmpName === '' || (!is_uploaded_file($tmpName) && !is_file($tmpName))) {
			// In a real request PHP guarantees is_uploaded_file for a legit
			// upload; the is_file fallback keeps this unit-testable with a plain
			// temp file. Anything else is a forged/absent tmp_name and must be
			// refused so an arbitrary server path is never read.
			throw new InvalidInputException('No export file uploaded');
		}

		// The authoritative size is what is actually on disk - a client may lie
		// about `size`.
		$actualSize = @filesize($tmpName);
		$size = $actualSize !== false ? $actualSize : (int)($upload['size'] ?? 0);
		if ($size <= 0) {
			throw new InvalidInputException('The export file is empty');
		}
		if ($size > ImportArchiveReader::MAX_TOTAL_BYTES) {
			throw new InvalidInputException('The export file is too large to import');
		}

		if ($this->looksLikeArchive($tmpName)) {
			return $this->importArchive($tmpName, $actorUid);
		}

		// A bare document is decoded whole, so it answers to the document cap,
		// not to the (much larger) archive cap checked above.
		if ($size > self::MAX_DOCUMENT_BYTES) {
			throw new InvalidInputException('The export file is too large to import');
		}
		$raw = @file_get_contents($tmpName);
		if ($raw === false) {
			throw new InvalidInputException('Could not read the uploaded export file');
		}
		return $this->import($raw, $actorUid);
	}

	/**
	 * Imports a v3 export ARCHIVE (the .zip {@see BoardArchiveService} writes)
	 * into a new board owned by the actor: `board.json` restores the graph, and
	 * every manifest entry's bytes become a real attachment on the new card.
	 *
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 * @throws InvalidInputException on an unreadable, hostile, oversized or unsupported archive
	 */
	public function importArchive(string $archivePath, string $actorUid): array {
		$archive = ImportArchiveReader::open($archivePath);
		try {
			$board = $this->validateDocument($archive->readDocument(self::MAX_DOCUMENT_BYTES));
			return $this->rebuildInTransaction($board, $actorUid, $archive);
		} finally {
			$archive->close();
		}
	}

	/**
	 * Imports a Kanso export DOCUMENT into a new board owned by the actor.
	 *
	 * Kept for the bare-JSON shape: every v1/v2 export already in a user's
	 * downloads folder, and any scripted client posting the raw text. A v3
	 * document sent this way imports its graph fine; its attachment manifest has
	 * no bytes to go with it, so those entries are logged and skipped - the
	 * archive is what carries files.
	 *
	 * @param string $rawDocument the raw uploaded/pasted JSON export
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 * @throws InvalidInputException on an oversized, malformed or unsupported document
	 */
	public function import(string $rawDocument, string $actorUid): array {
		return $this->rebuildInTransaction($this->validateDocument($rawDocument), $actorUid);
	}

	/**
	 * Parses and version-checks an export document, returning its board node.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidInputException on an oversized, malformed or unsupported document
	 */
	private function validateDocument(string $rawDocument): array {
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
		return $board;
	}

	/**
	 * Whether the uploaded file starts with the local-file-header magic every
	 * zip begins with. Content, not filename or declared MIME - the client
	 * controls both of those and neither decides how the bytes are parsed.
	 */
	private function looksLikeArchive(string $path): bool {
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			return false;
		}
		$magic = fread($handle, 4);
		fclose($handle);
		return $magic === "PK\x03\x04";
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
	 * the document import path, the archive import path and the in-process
	 * duplicate path.
	 *
	 * The DB half rolls back on its own. The app-data half does NOT take part in
	 * the transaction, so every attachment object written during the rebuild is
	 * tracked and best-effort removed when the rebuild throws - otherwise a
	 * failed import would leave orphaned bytes behind with no row referencing
	 * them (and so nothing to ever clean them up).
	 *
	 * @param array<string, mixed> $board
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 */
	private function rebuildInTransaction(array $board, string $actorUid, ?ImportArchiveReader $archive = null): array {
		/** @var list<array{cardId: int, storageKey: string}> $writtenObjects */
		$writtenObjects = [];
		$this->db->beginTransaction();
		try {
			$result = $this->rebuild($board, $actorUid, $archive, $writtenObjects);
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			$this->cleanupWrittenObjects($writtenObjects);
			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $board
	 * @param list<array{cardId: int, storageKey: string}> $writtenObjects tracked, by-reference
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int}
	 */
	private function rebuild(
		array $board,
		string $actorUid,
		?ImportArchiveReader $archive,
		array &$writtenObjects,
	): array {
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
			$board, $boardId, $stackIdMap, $labelIdMap, $reviewTypeIdMap, $actorUid, $now,
			$archive, $writtenObjects
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
	 * @param list<array{cardId: int, storageKey: string}> $writtenObjects tracked, by-reference
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
		?ImportArchiveReader $archive,
		array &$writtenObjects,
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
			$this->attachFiles($row, $new->getId(), $boardId, $actorUid, $archive, $writtenObjects);
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
			// Older archives predate the field; absent means "open".
			$comment->setResolvedAt((int)($c['resolvedAt'] ?? 0));

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
	 * Restores the card's ATTACHMENT BYTES out of the export archive (#10071) -
	 * the half of a board that a document alone cannot carry.
	 *
	 * Nothing here trusts the archive:
	 *  - the manifest `path` is only a LOOKUP KEY into
	 *    {@see ImportArchiveReader}'s validated index. It never becomes a
	 *    filesystem path: the object is written under a fresh `secureRandom`
	 *    key in `card-<new card id>/`, exactly like an upload
	 *    ({@see CardAttachmentService::upload()}). Both path components are
	 *    server-generated, so an entry name cannot select where bytes land no
	 *    matter what it says;
	 *  - the manifest `mime` is DISCARDED and the type is re-derived from the
	 *    bytes on disk, then run through {@see AttachmentSanitizer::mime()} - so
	 *    an entry announcing `image/png` over HTML is stored (and later served)
	 *    as inert `application/octet-stream`;
	 *  - the manifest `filename` is a display label only, sanitized on the way
	 *    in like every other store path;
	 *  - `size` is the byte count actually written, not the declared one.
	 *
	 * Permission-wise there is nothing extra to gate: import always builds a
	 * BRAND-NEW board owned by the actor ({@see rebuild()} calls
	 * {@see BoardService::create} with the actor uid), so a restored file can
	 * only ever land on a card of a board the importer just created and owns.
	 * There is no board id in the document that could redirect it elsewhere.
	 *
	 * A manifest entry whose bytes are missing from the archive is logged and
	 * skipped, never fatal - one absent blob must not cost a user the rest of
	 * the restore. A bomb or a cap breach, in contrast, throws and takes the
	 * whole import down (the transaction rolls back and
	 * {@see cleanupWrittenObjects()} removes what already landed).
	 *
	 * @param array<string, mixed> $row
	 * @param list<array{cardId: int, storageKey: string}> $writtenObjects tracked, by-reference
	 */
	private function attachFiles(
		array $row,
		int $newCardId,
		int $boardId,
		string $actorUid,
		?ImportArchiveReader $archive,
		array &$writtenObjects,
	): void {
		// A bare document carries a manifest but no bytes; there is nothing to
		// restore and that is not an error.
		if ($archive === null) {
			return;
		}

		foreach ((array)($row['attachments'] ?? []) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$path = $this->str($entry, 'path', '');
			if ($path === '' || !$archive->has($path)) {
				$this->logger->warning(
					'Kanso import: an attachment listed on a card is missing from the export archive',
					['cardId' => $newCardId],
				);
				continue;
			}

			$scratch = $this->tempManager->getTemporaryFile('.att');
			if ($scratch === false) {
				throw new \RuntimeException('No writable temp directory for the import');
			}
			try {
				$size = $archive->copyEntryTo($path, $scratch);
				if ($size === null || $size <= 0) {
					$this->logger->warning(
						'Kanso import: an attachment could not be read out of the export archive',
						['cardId' => $newCardId],
					);
					continue;
				}
				$this->storeAttachment($entry, $newCardId, $boardId, $scratch, $size, $actorUid, $writtenObjects);
			} finally {
				@unlink($scratch);
			}
		}
	}

	/**
	 * Writes one spooled attachment into app-data under a server-generated key
	 * and inserts its `kanso_card_attachments` row, recording the object so a
	 * later failure can undo it.
	 *
	 * @param array<string, mixed> $entry the manifest entry (untrusted metadata)
	 * @param list<array{cardId: int, storageKey: string}> $writtenObjects tracked, by-reference
	 */
	private function storeAttachment(
		array $entry,
		int $newCardId,
		int $boardId,
		string $scratchPath,
		int $size,
		string $actorUid,
		array &$writtenObjects,
	): void {
		$filename = AttachmentSanitizer::filename($this->str($entry, 'filename', ''));
		$mime = $this->resolveMime($scratchPath, $filename);

		$stream = @fopen($scratchPath, 'rb');
		if ($stream === false) {
			throw new \RuntimeException('Could not read a spooled attachment');
		}
		// SERVER-GENERATED opaque object name - the archive entry name never
		// touches the storage path (identical to the upload path).
		$storageKey = $this->secureRandom->generate(
			32,
			ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
		);
		try {
			$this->cardFolder($newCardId)->newFile($storageKey, $stream);
		} finally {
			// newFile() may consume and close the stream itself; only close it
			// if it is still an open resource.
			/** @psalm-suppress TypeDoesNotContainType, RedundantCondition, DocblockTypeContradiction */
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		$writtenObjects[] = ['cardId' => $newCardId, 'storageKey' => $storageKey];

		// The recorded uploader is kept when that uid still exists here,
		// otherwise the importer takes it (the card-owner rule).
		$uploadedBy = $this->nullableStr($entry, 'uploadedBy');
		if ($uploadedBy === null || !$this->userManager->userExists($uploadedBy)) {
			$uploadedBy = $actorUid;
		}
		$createdAt = (int)($entry['createdAt'] ?? 0);

		$attachment = new CardAttachment();
		$attachment->setCardId($newCardId);
		$attachment->setBoardId($boardId);
		$attachment->setFilename($filename);
		$attachment->setMime($mime);
		$attachment->setSize($size);
		$attachment->setStorageKey($storageKey);
		$attachment->setUploadedBy($uploadedBy);
		$attachment->setCreatedAt($createdAt > 0 ? $createdAt : time());
		$this->cardAttachmentMapper->insert($attachment);
	}

	/**
	 * The MIME to store for restored bytes, derived SERVER-SIDE from the bytes
	 * themselves and from the sanitized display name - never from the manifest,
	 * which an attacker writes.
	 *
	 * Both readings go through {@see AttachmentSanitizer::mime()}, whose denylist
	 * is the one thing keeping active content (html/svg/xml/js) out of the store.
	 * If EITHER reading trips it the stored type is the inert generic one: a
	 * `.png` full of HTML is refused by the content sniff, and an `.html` full of
	 * PNG bytes is refused by the name.
	 */
	private function resolveMime(string $path, string $filename): string {
		$fromContent = AttachmentSanitizer::mime($this->mimeTypeDetector->detectContent($path));
		$fromName = AttachmentSanitizer::mime($this->mimeTypeDetector->detectPath($filename));
		if ($fromContent === 'application/octet-stream' || $fromName === 'application/octet-stream') {
			return 'application/octet-stream';
		}
		return $fromContent;
	}

	/**
	 * The per-card app-data folder, created on demand - the same `card-<id>`
	 * layout {@see CardAttachmentService} uses. Card ids are integers freshly
	 * assigned by this import, so the folder name is never attacker-controlled.
	 */
	private function cardFolder(int $cardId): ISimpleFolder {
		$name = self::FOLDER_PREFIX . $cardId;
		try {
			return $this->appData->getFolder($name);
		} catch (NotFoundException) {
			return $this->appData->newFolder($name);
		}
	}

	/**
	 * Best-effort removal of the app-data objects written during a failed
	 * import. A missing object/folder is fine - the point is that nothing the
	 * rolled-back transaction no longer references is left occupying storage.
	 *
	 * @param list<array{cardId: int, storageKey: string}> $writtenObjects
	 */
	private function cleanupWrittenObjects(array $writtenObjects): void {
		foreach ($writtenObjects as $object) {
			try {
				$this->appData
					->getFolder(self::FOLDER_PREFIX . $object['cardId'])
					->getFile($object['storageKey'])
					->delete();
			} catch (\Throwable) {
				// Nothing to clean up.
			}
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
				// Asking for the occurrence after `$now - 1` makes the target the
				// anchor itself, so the expansion runs every guard and builds the
				// iterator but never STEPS it. That matters here more than anywhere:
				// this loop runs once per rule in an uploaded file, the result is
				// discarded, and the catch below only catches EXCEPTIONS - a rule that
				// spins inside the iterator instead of throwing would wedge the whole
				// import, which is a single authenticated request an attacker fully
				// controls. Zero steps means zero opportunity.
				$this->recurrenceService->computeNextOccurrence($rrule, $now - 1, $now);
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

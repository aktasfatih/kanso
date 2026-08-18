<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * One-click import of a Deck board into Kanso. Reads the source via
 * {@see DeckReader} (read-only) and writes a fresh Kanso board owned by the
 * importing user - a copy, never a link, so the two stay independent.
 *
 * v1 imports the structure that maps cleanly: board (title/color), stacks
 * (order preserved), cards (title/description/archived/due date/done state),
 * labels + their card assignments, user assignees that still exist, card
 * comments (author remapped to the importer when the uid is gone), and
 * `deck_file` attachments (the bytes copied out of Deck's app-data into Kanso's
 * own). Deck's user-Files reference attachments (the `file` kind) are NOT
 * re-linked - they are counted and reported as skipped. Board SHARING/ACL is
 * still out of scope for v1 (it needs participant remapping).
 */
class DeckImportService {
	/**
	 * Import-side title column limits, matching the STRING(100) title columns
	 * in {@see \OCA\Kanso\Migration\Version000100Date20260722000000} for boards,
	 * stacks, and labels. Card titles reuse {@see CardService::MAX_TITLE_LENGTH}
	 * (also 100), which owns the canonical card-title cap.
	 */
	private const MAX_BOARD_TITLE_LENGTH = 100;
	private const MAX_STACK_TITLE_LENGTH = 100;
	private const MAX_LABEL_TITLE_LENGTH = 100;

	public function __construct(
		private DeckReader $deckReader,
		private BoardService $boardService,
		private StackMapper $stackMapper,
		private CardMapper $cardMapper,
		private LabelMapper $labelMapper,
		private CardLabelMapper $cardLabelMapper,
		private CardAssigneeMapper $cardAssigneeMapper,
		private CommentMapper $commentMapper,
		private CardAttachmentMapper $cardAttachmentMapper,
		private SortKeyService $sortKeyService,
		private IUserManager $userManager,
		private IDBConnection $db,
		private IAppData $appData,
		private IAppDataFactory $appDataFactory,
		private ISecureRandom $secureRandom,
	) {
	}

	/**
	 * The Deck boards the user can import (owned or directly shared), or an empty
	 * list when Deck is not installed.
	 *
	 * @return list<array{id: int, title: string, color: ?string, archived: bool, cardCount: int}>
	 */
	public function listImportableBoards(string $actorUid): array {
		if (!$this->deckReader->isAvailable()) {
			return [];
		}
		return $this->deckReader->listImportableBoards($actorUid);
	}

	/** Whether the Deck app is available to import from at all. */
	public function isDeckAvailable(): bool {
		return $this->deckReader->isAvailable();
	}

	/**
	 * Imports one Deck board into a new Kanso board owned by the actor.
	 *
	 * @return array{boardId: int, title: string, stacks: int, cards: int, labels: int, comments: int, attachments: int, skippedFileAttachments: int}
	 * @throws InvalidInputException if Deck is not available
	 * @throws NotPermittedException if the actor cannot read the Deck board
	 * @throws DoesNotExistException if the Deck board does not exist
	 */
	public function importBoard(int $deckBoardId, string $actorUid): array {
		if (!$this->deckReader->isAvailable()) {
			throw new InvalidInputException('The Deck app is not available to import from');
		}
		if (!$this->deckReader->userCanReadBoard($actorUid, $deckBoardId)) {
			throw new NotPermittedException('You do not have access to that Deck board');
		}
		$deckBoard = $this->deckReader->readBoard($deckBoardId);
		if ($deckBoard === null) {
			throw new DoesNotExistException('Deck board ' . $deckBoardId . ' does not exist');
		}

		// All-or-nothing on the DB side (rolled back on any failure). App-data
		// byte writes are NOT transactional, so every object we write is tracked
		// here and best-effort cleaned up if the import throws after it landed.
		/** @var list<array{cardId: int, storageKey: string}> $writtenObjects */
		$writtenObjects = [];
		$this->db->beginTransaction();
		try {
			// BoardService::create() validates the title and would throw on a
			// >100-char source (aborting the whole import), so pre-truncate it
			// here to the same STRING(100) board-title limit.
			$boardTitle = TitleSanitizer::truncate($deckBoard['title'], self::MAX_BOARD_TITLE_LENGTH);
			$board = $this->boardService->create($boardTitle, $deckBoard['color'], $actorUid);
			$boardId = $board->getId();
			$now = time();

			// Labels first, so card assignments can reference the new ids.
			$labelIdMap = [];
			foreach ($this->deckReader->readLabels($deckBoardId) as $dl) {
				$label = new Label();
				$label->setBoardId($boardId);
				// Label title column is STRING(100); safe multibyte truncation.
				$label->setTitle(TitleSanitizer::truncate($dl['title'], self::MAX_LABEL_TITLE_LENGTH));
				$label->setColor($dl['color']);
				$labelIdMap[$dl['id']] = $this->labelMapper->insert($label)->getId();
			}

			// Stacks (order preserved via sequential sort keys), then their cards.
			$cardIdMap = [];
			$deckCardIds = [];
			$stackCount = 0;
			$cardCount = 0;
			$stackKey = null;
			foreach ($this->deckReader->readStacks($deckBoardId) as $ds) {
				$stack = new Stack();
				$stack->setBoardId($boardId);
				// Stack title column is STRING(100); no description to spill the
				// remainder into, so a long title is just safely truncated.
				$stack->setTitle(TitleSanitizer::truncate($ds['title'], self::MAX_STACK_TITLE_LENGTH));
				$stackKey = $stackKey === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($stackKey);
				$stack->setSortKey($stackKey);
				$stack->setArchived(false);
				$stack->setRole(Stack::ROLE_NONE);
				$stack->setWipLimit(null);
				$stack->setDeletedAt(0);
				$newStackId = $this->stackMapper->insert($stack)->getId();
				$stackCount++;

				$cardKey = null;
				foreach ($this->deckReader->readCards($ds['id']) as $dc) {
					$card = new Card();
					$card->setBoardId($boardId);
					$card->setStackId($newStackId);
					// A Deck title longer than the STRING(100) column would abort
					// the import. Truncate it (multibyte-safe) and preserve the
					// full original by prepending it to the (unbounded TEXT)
					// description, so no data is lost. Empty/whitespace titles get
					// a placeholder - Kanso requires a non-empty title.
					$title = (string)$dc['title'];
					$description = (string)$dc['description'];
					if (TitleSanitizer::isOverLength($title, CardService::MAX_TITLE_LENGTH)) {
						$fullTitle = trim($title);
						$description = 'Full title: ' . $fullTitle
							. ($description !== '' ? "\n\n" . $description : '');
					}
					$card->setTitle(TitleSanitizer::truncate($title, CardService::MAX_TITLE_LENGTH));
					$card->setDescription($description);
					$cardKey = $cardKey === null ? $this->sortKeyService->initial() : $this->sortKeyService->after($cardKey);
					$card->setSortKey($cardKey);
					$card->setDuedate($dc['duedate'] !== null ? (new \DateTime())->setTimestamp($dc['duedate']) : null);
					$card->setDoneAt($dc['doneAt']);
					$card->setArchived($dc['archived']);
					$card->setOwner($actorUid);
					$card->setCreatedAt($dc['createdAt'] > 0 ? $dc['createdAt'] : $now);
					$card->setLastModified($now);
					$card->setDeletedAt(0);
					$card->setParentCardId(null);
					$card->setPriority(0);
					$newCardId = $this->cardMapper->insert($card)->getId();
					$cardIdMap[$dc['id']] = $newCardId;
					$deckCardIds[] = $dc['id'];
					$cardCount++;
				}
			}

			$this->importLabelAssignments($deckCardIds, $cardIdMap, $labelIdMap);
			$this->importUserAssignees($deckCardIds, $cardIdMap);
			$commentCount = $this->importComments($deckCardIds, $cardIdMap, $actorUid);
			$attachmentCount = $this->importAttachments($deckCardIds, $cardIdMap, $actorUid, $writtenObjects);
			$skippedFileAttachments = $this->deckReader->countFileReferenceAttachments($deckCardIds);

			$result = [
				'boardId' => $boardId,
				'title' => $board->getTitle(),
				'stacks' => $stackCount,
				'cards' => $cardCount,
				'labels' => count($labelIdMap),
				'comments' => $commentCount,
				'attachments' => $attachmentCount,
				'skippedFileAttachments' => $skippedFileAttachments,
			];
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// The DB is rolled back, but any app-data bytes we already copied are
			// not - remove them so a failed import strands no orphan objects.
			$this->cleanupWrittenObjects($writtenObjects);
			throw $e;
		}
	}

	/**
	 * @param int[] $deckCardIds
	 * @param array<int, int> $cardIdMap deck card id → kanso card id
	 * @param array<int, int> $labelIdMap deck label id → kanso label id
	 */
	private function importLabelAssignments(array $deckCardIds, array $cardIdMap, array $labelIdMap): void {
		foreach ($this->deckReader->readAssignedLabels($deckCardIds) as $deckCardId => $labelIds) {
			$newCardId = $cardIdMap[$deckCardId] ?? null;
			if ($newCardId === null) {
				continue;
			}
			foreach ($labelIds as $deckLabelId) {
				$newLabelId = $labelIdMap[$deckLabelId] ?? null;
				if ($newLabelId !== null) {
					$this->cardLabelMapper->insertAssignment($newCardId, $newLabelId);
				}
			}
		}
	}

	/**
	 * @param int[] $deckCardIds
	 * @param array<int, int> $cardIdMap deck card id → kanso card id
	 */
	private function importUserAssignees(array $deckCardIds, array $cardIdMap): void {
		foreach ($this->deckReader->readAssignedUsers($deckCardIds) as $deckCardId => $uids) {
			$newCardId = $cardIdMap[$deckCardId] ?? null;
			if ($newCardId === null) {
				continue;
			}
			foreach ($uids as $uid) {
				// Only assign users that still exist on this instance.
				if ($this->userManager->userExists($uid)) {
					$this->cardAssigneeMapper->insertAssignment($newCardId, $uid);
				}
			}
		}
	}

	/**
	 * Imports Deck card comments into the new Kanso cards. Inserted via the
	 * mapper DIRECTLY (never CommentService::addComment) so a bulk import fires
	 * no subscriptions, @mention notifications, or per-comment change rows.
	 *
	 * Comments are inserted oldest-first so a reply's parent already has a new id
	 * by the time the reply is inserted. An author uid that no longer exists is
	 * remapped to the importing user (never dropped). created-at is preserved.
	 *
	 * @param int[] $deckCardIds
	 * @param array<int, int> $cardIdMap deck card id → kanso card id
	 * @return int the number of comments inserted
	 */
	private function importComments(array $deckCardIds, array $cardIdMap, string $actorUid): int {
		$count = 0;
		$commentIdMap = [];
		foreach ($this->deckReader->readComments($deckCardIds) as $dc) {
			$newCardId = $cardIdMap[$dc['cardId']] ?? null;
			if ($newCardId === null) {
				continue;
			}
			$author = $dc['author'];
			if ($author === '' || !$this->userManager->userExists($author)) {
				$author = $actorUid;
			}

			$comment = new Comment();
			$comment->setCardId($newCardId);
			$comment->setAuthor($author);
			$comment->setBody($dc['message']);
			$comment->setCreatedAt($dc['createdAt']);
			$comment->setEditedAt(0);
			$comment->setDeletedAt(0);
			// Remap the parent to the already-inserted top-level comment; an
			// unknown/missing parent falls back to a top-level comment.
			$comment->setParentCommentId($dc['parentId'] > 0 ? ($commentIdMap[$dc['parentId']] ?? null) : null);

			$new = $this->commentMapper->insert($comment);
			$commentIdMap[$dc['id']] = $new->getId();
			$count++;
		}
		return $count;
	}

	/**
	 * Copies Deck's `deck_file` attachments into the new Kanso cards: the bytes
	 * are read from Deck's app-data and re-stored under a server-generated
	 * storage key in Kanso's own app-data, then a `kanso_card_attachments` row is
	 * inserted. The user-Files reference kind (`file`) is not handled here (it is
	 * counted separately and reported as skipped).
	 *
	 * A `deck_attachment` row whose source object is MISSING - or whose source
	 * exceeds {@see AttachmentSanitizer::MAX_SIZE} - is skipped and NOT counted,
	 * never failing the whole import. The copied filename/MIME run through
	 * {@see AttachmentSanitizer} for the same hardening as the upload path (an
	 * imported `.html`/`.svg` can never become stored XSS). Every object we do
	 * write is appended to $writtenObjects so the caller can clean it up if a
	 * later step throws (app-data writes are not covered by the DB transaction).
	 *
	 * @param int[] $deckCardIds
	 * @param array<int, int> $cardIdMap deck card id → kanso card id
	 * @param list<array{cardId: int, storageKey: string}> $writtenObjects tracked, by-reference
	 * @return int the number of attachments copied + linked
	 */
	private function importAttachments(array $deckCardIds, array $cardIdMap, string $actorUid, array &$writtenObjects): int {
		$attachments = $this->deckReader->readAttachments($deckCardIds);
		if ($attachments === []) {
			return 0;
		}
		$deckAppData = $this->appDataFactory->get('deck');

		$count = 0;
		foreach ($attachments as $att) {
			$newCardId = $cardIdMap[$att['cardId']] ?? null;
			if ($newCardId === null) {
				continue;
			}

			// Resolve the source object from Deck's app-data. A missing source
			// object (row present but file gone) is skipped, not fatal.
			try {
				$sourceFile = $deckAppData->getFolder((string)$att['cardId'])->getFile($att['data']);
			} catch (NotFoundException) {
				continue;
			}

			// Cap the source size BEFORE reading any bytes (mirrors the upload +
			// Files-copy paths). An oversized source is skipped-and-not-counted,
			// exactly like a missing source - never fatal to the whole import.
			if ((int)$sourceFile->getSize() > AttachmentSanitizer::MAX_SIZE) {
				continue;
			}
			$bytes = $sourceFile->getContent();

			$card = $this->cardMapper->find($newCardId);
			$boardId = $card->getBoardId();

			// Server-generated opaque object name - the Deck filename never selects
			// a storage path (mirrors CardAttachmentService).
			$storageKey = $this->secureRandom->generate(
				32,
				ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
			);
			$folder = $this->kansoCardFolder($newCardId);
			$folder->newFile($storageKey, $bytes);
			$writtenObjects[] = ['cardId' => $newCardId, 'storageKey' => $storageKey];

			$author = $att['createdBy'];
			if ($author === '' || !$this->userManager->userExists($author)) {
				$author = $actorUid;
			}

			$attachment = new CardAttachment();
			$attachment->setCardId($newCardId);
			$attachment->setBoardId($boardId);
			$attachment->setFilename(AttachmentSanitizer::filename($att['data']));
			$attachment->setMime(AttachmentSanitizer::mime($sourceFile->getMimeType()));
			$attachment->setSize((int)$sourceFile->getSize());
			$attachment->setStorageKey($storageKey);
			$attachment->setUploadedBy($author);
			$attachment->setCreatedAt($att['createdAt'] > 0 ? $att['createdAt'] : time());
			$this->cardAttachmentMapper->insert($attachment);
			$count++;
		}
		return $count;
	}

	/**
	 * The per-card app-data folder in Kanso's OWN app-data, created on demand -
	 * the same `card-<id>` layout as {@see CardAttachmentService}. Card ids are
	 * integers, so the folder name is never attacker-controlled.
	 */
	private function kansoCardFolder(int $cardId): ISimpleFolder {
		$name = 'card-' . $cardId;
		try {
			return $this->appData->getFolder($name);
		} catch (NotFoundException) {
			return $this->appData->newFolder($name);
		}
	}

	/**
	 * Best-effort removal of the app-data objects written during a failed import.
	 * A missing object/folder is fine.
	 *
	 * @param list<array{cardId: int, storageKey: string}> $writtenObjects
	 */
	private function cleanupWrittenObjects(array $writtenObjects): void {
		foreach ($writtenObjects as $obj) {
			try {
				$this->appData->getFolder('card-' . $obj['cardId'])->getFile($obj['storageKey'])->delete();
			} catch (\Throwable) {
				// Nothing to clean up.
			}
		}
	}
}

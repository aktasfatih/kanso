<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardTimeEntry;
use OCA\Kanso\Db\CardTimeEntryMapper;
use OCA\Kanso\Db\Change;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Manual time tracking on a card (#3536). Each row is a duration (in seconds)
 * an actor logged against the card, with an optional note; the per-card total
 * is the SUM of these rows and lives only in the card DETAIL payload (never the
 * board/summary listings), so the "summaries only" board perf bet is preserved.
 *
 * MANUAL entries only - there is deliberately no running-timer state on the
 * server; a client stopwatch just POSTs a finished duration on stop.
 *
 * Gating mirrors {@see CardAttachmentService}: list requires READ, add/delete
 * require EDIT, and every mutation reuses the card's ENTITY_CARD / ACTION_UPDATE
 * change row so the existing realtime/delta-sync + ETag path reflects the new
 * total with no new Change type. Delete is IDOR-guarded (the entry must belong
 * to the card in the URL, otherwise a 404 - never a leak).
 */
class CardTimeEntryService {
	/**
	 * Hard cap on a single entry's duration (in seconds): 1000 hours. A manual
	 * entry larger than this is almost certainly a bad input (e.g. minutes fed
	 * as seconds) and is rejected rather than stored.
	 */
	public const MAX_SECONDS = 1000 * 3600;

	/** Max stored note length (mirrors the note column width). */
	private const MAX_NOTE_LENGTH = 255;

	public function __construct(
		private CardTimeEntryMapper $timeEntryMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private ChangeNotifier $changeNotifier,
	) {
	}

	/**
	 * A card's time entries (newest first). Requires READ.
	 *
	 * @return CardTimeEntry[]
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function listForCard(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		return $this->timeEntryMapper->findByCard($cardId);
	}

	/**
	 * Logs a manual time entry against the card. Requires EDIT.
	 *
	 * The board id is denormalized from the loaded card (server-side, never
	 * client-supplied). `$seconds` must be positive and within {@see
	 * self::MAX_SECONDS}; the note is trimmed and length-capped.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the duration is zero/negative or absurdly large
	 */
	public function add(int $cardId, int $seconds, ?string $note, string $actorUid): CardTimeEntry {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		if ($seconds <= 0) {
			throw new InvalidInputException('Duration must be greater than zero');
		}
		if ($seconds > self::MAX_SECONDS) {
			throw new InvalidInputException('Duration is too large');
		}

		$entry = new CardTimeEntry();
		$entry->setCardId($cardId);
		$entry->setBoardId($card->getBoardId());
		$entry->setSeconds($seconds);
		$entry->setNote($this->sanitizeNote($note));
		$entry->setCreatedBy($actorUid);
		$entry->setCreatedAt(time());

		$entry = $this->timeEntryMapper->insert($entry);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid
		);

		return $entry;
	}

	/**
	 * Removes a time entry from the card. Requires EDIT. IDOR-guarded: the entry
	 * must belong to the card in the URL, otherwise a 404 (not a leak).
	 *
	 * @throws DoesNotExistException if the card/board/entry does not exist, is deleted, or the entry is on another card
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function delete(int $cardId, int $entryId, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		$entry = $this->loadEntryOnCard($entryId, $cardId);
		$this->timeEntryMapper->delete($entry);

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid
		);
	}

	/**
	 * Cascade cleanup when a card is PERMANENTLY removed (trash purge). Drops
	 * every time-entry row of the card in a single statement.
	 *
	 * No permission check and no change notification: this is an internal
	 * cascade invoked by callers ({@see TrashService::purge()}) that have
	 * already authorized the destructive card removal and emit their own card
	 * DELETE change row. Safe to call for a card with zero entries.
	 */
	public function deleteAllForCard(int $cardId): void {
		$this->timeEntryMapper->deleteByCard($cardId);
	}

	/**
	 * Loads an entry and asserts it belongs to $cardId - the IDOR guard. A
	 * mismatch is a 404 (not found on THIS card), never a leak.
	 *
	 * @throws DoesNotExistException if the entry does not exist or is on another card
	 */
	private function loadEntryOnCard(int $entryId, int $cardId): CardTimeEntry {
		$entry = $this->timeEntryMapper->find($entryId);
		if ($entry->getCardId() !== $cardId) {
			throw new DoesNotExistException('Time entry ' . $entryId . ' is not on card ' . $cardId);
		}
		return $entry;
	}

	/**
	 * Trims and length-caps a client note; an empty note is stored as null.
	 */
	private function sanitizeNote(?string $note): ?string {
		if ($note === null) {
			return null;
		}
		$note = trim($note);
		if ($note === '') {
			return null;
		}
		if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
			$note = mb_substr($note, 0, self::MAX_NOTE_LENGTH);
		}
		return $note;
	}

	/**
	 * @throws DoesNotExistException if the card does not exist or is deleted
	 */
	private function loadCard(int $id): Card {
		$card = $this->cardMapper->find($id);
		if ($card->getDeletedAt() > 0) {
			throw new DoesNotExistException('Card ' . $id . ' is deleted');
		}
		return $card;
	}

	/**
	 * @throws DoesNotExistException if the board does not exist or is deleted
	 */
	private function loadBoard(int $boardId): Board {
		$board = $this->boardMapper->find($boardId);
		if ($board->getDeletedAt() > 0) {
			throw new DoesNotExistException('Board ' . $boardId . ' is deleted');
		}
		return $board;
	}
}

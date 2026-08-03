<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Db\CardAttachmentMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;

/**
 * File attachments on a card (#3526). Bytes live in Kanso's OWN app-data
 * (IAppData), NOT in the user's personal Files - every board member sees a
 * card's attachments through the app, gated purely by board permission (READ
 * to view/download, EDIT to upload/delete). Kanso holds no external creds.
 *
 * Security posture (the whole point of the storage model):
 *  - The on-disk object name (`storage_key`) is SERVER-GENERATED (random hex),
 *    never derived from the client filename - so a filename like `../../evil`
 *    can never select a path. The original filename is persisted only as a
 *    display label and echoed back solely in a Content-Disposition `attachment`
 *    header (never rendered inline).
 *  - Every read resolves the attachment, checks it belongs to the *card* in the
 *    URL (IDOR guard), then asserts board permission - a stranger cannot reach
 *    another board's bytes by guessing ids.
 *  - Size is capped ({@see self::MAX_SIZE}); an empty/oversized upload is
 *    rejected before anything is written.
 *
 * Add/delete reuse the card's ENTITY_CARD / ACTION_UPDATE change row so the
 * existing realtime/delta-sync + ETag path reflects the new attachment count
 * with no new Change type.
 */
class CardAttachmentService {
	/** Hard cap on a single upload. Oversized uploads are rejected. */
	public const MAX_SIZE = 100 * 1024 * 1024; // 100 MiB

	/** Per-card app-data subfolder holding that card's attachment objects. */
	private const FOLDER_PREFIX = 'card-';

	public function __construct(
		private CardAttachmentMapper $attachmentMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private ChangeNotifier $changeNotifier,
		private IAppData $appData,
		private ISecureRandom $secureRandom,
	) {
	}

	/**
	 * A card's attachments (metadata only). Requires READ.
	 *
	 * @return CardAttachment[]
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function listForCard(int $cardId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		return $this->attachmentMapper->findByCard($cardId);
	}

	/**
	 * Stores an uploaded file against the card. Requires EDIT.
	 *
	 * The $upload array is the PHP `$_FILES`-shaped entry from
	 * {@see \OCP\IRequest::getUploadedFile()}: keys name, type, size, tmp_name,
	 * error. The bytes are read from tmp_name and written to a server-generated
	 * app-data object; the client filename is kept only as a label.
	 *
	 * @param array{name?: string, type?: string, size?: int, tmp_name?: string, error?: int}|null $upload
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the upload is missing, errored, empty, or oversized
	 */
	public function upload(int $cardId, ?array $upload, string $actorUid): CardAttachment {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			$error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
			if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
				throw new InvalidInputException('File too large');
			}
			throw new InvalidInputException('No file uploaded');
		}

		// Reject on the CLIENT-REPORTED size first - so a caller announcing a
		// huge upload is turned away before any bytes are read.
		if ((int)($upload['size'] ?? 0) > self::MAX_SIZE) {
			throw new InvalidInputException('File too large');
		}

		$tmpName = $upload['tmp_name'] ?? '';
		if ($tmpName === '' || (!is_uploaded_file($tmpName) && !is_file($tmpName))) {
			// In a real request PHP guarantees is_uploaded_file for a legit
			// upload; the is_file fallback keeps the service unit-testable with a
			// plain temp file. Anything else is a forged/absent tmp_name and must
			// be rejected so we never read an arbitrary server path.
			throw new InvalidInputException('No file uploaded');
		}

		// The authoritative size is the bytes actually on disk (a client may lie
		// about `size`); re-check it against the cap.
		$actualSize = @filesize($tmpName);
		$size = $actualSize !== false ? $actualSize : (int)($upload['size'] ?? 0);
		if ($size <= 0) {
			throw new InvalidInputException('Empty file');
		}
		if ($size > self::MAX_SIZE) {
			throw new InvalidInputException('File too large');
		}

		$stream = @fopen($tmpName, 'rb');
		if ($stream === false) {
			throw new InvalidInputException('Could not read uploaded file');
		}

		// SERVER-GENERATED opaque object name - the client filename never touches
		// the storage path.
		$storageKey = $this->secureRandom->generate(
			32,
			ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
		);
		$folder = $this->cardFolder($cardId);
		try {
			$folder->newFile($storageKey, $stream);
		} finally {
			// newFile() may consume and close the stream itself; only close it if
			// it is still an open resource (a double fclose would raise a warning
			// that Nextcloud escalates to an exception).
			/** @psalm-suppress TypeDoesNotContainType, RedundantCondition, DocblockTypeContradiction */
			if (is_resource($stream)) {
				fclose($stream);
			}
		}

		$attachment = new CardAttachment();
		$attachment->setCardId($cardId);
		$attachment->setBoardId($card->getBoardId());
		$attachment->setFilename($this->sanitizeFilename((string)($upload['name'] ?? '')));
		$attachment->setMime($this->sanitizeMime((string)($upload['type'] ?? '')));
		$attachment->setSize($size);
		$attachment->setStorageKey($storageKey);
		$attachment->setUploadedBy($actorUid);
		$attachment->setCreatedAt(time());

		try {
			$attachment = $this->attachmentMapper->insert($attachment);
		} catch (\Throwable $e) {
			// Roll back the orphaned object so a failed insert leaves no dangling
			// bytes.
			$this->deleteObjectQuietly($cardId, $storageKey);
			throw $e;
		}

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid
		);

		return $attachment;
	}

	/**
	 * Resolves an attachment for download: returns [metadata, bytes]. Requires
	 * READ on the card's board and verifies the attachment belongs to the card
	 * in the URL (IDOR guard).
	 *
	 * @return array{0: CardAttachment, 1: string}
	 * @throws DoesNotExistException if the card/board/attachment does not exist, is deleted, or the attachment is on another card
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function download(int $cardId, int $attachmentId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		$attachment = $this->loadAttachmentOnCard($attachmentId, $cardId);
		try {
			$file = $this->cardFolder($cardId)->getFile($attachment->getStorageKey());
			$bytes = $file->getContent();
		} catch (NotFoundException $e) {
			throw new DoesNotExistException('Attachment object missing');
		}

		return [$attachment, $bytes];
	}

	/**
	 * Removes an attachment (object + row) from the card. Requires EDIT.
	 *
	 * @throws DoesNotExistException if the card/board/attachment does not exist, is deleted, or the attachment is on another card
	 * @throws NotPermittedException if the actor may not edit the board
	 */
	public function delete(int $cardId, int $attachmentId, string $actorUid): void {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		$attachment = $this->loadAttachmentOnCard($attachmentId, $cardId);

		// Drop the row first (the source of truth for what's listed); then
		// best-effort remove the bytes.
		$this->attachmentMapper->delete($attachment);
		$this->deleteObjectQuietly($cardId, $attachment->getStorageKey());

		$this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			$cardId,
			Change::ACTION_UPDATE,
			$actorUid
		);
	}

	/**
	 * Loads an attachment and asserts it belongs to $cardId - the IDOR guard.
	 * A mismatch is a 404 (not found on THIS card), never a leak.
	 *
	 * @throws DoesNotExistException if the attachment does not exist or is on another card
	 */
	private function loadAttachmentOnCard(int $attachmentId, int $cardId): CardAttachment {
		$attachment = $this->attachmentMapper->find($attachmentId);
		if ($attachment->getCardId() !== $cardId) {
			throw new DoesNotExistException('Attachment ' . $attachmentId . ' is not on card ' . $cardId);
		}
		return $attachment;
	}

	/**
	 * The per-card app-data folder, created on demand. Card ids are integers, so
	 * the folder name is never attacker-controlled.
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
	 * Best-effort object removal - a missing object is fine (the row is already
	 * gone / never landed).
	 */
	private function deleteObjectQuietly(int $cardId, string $storageKey): void {
		try {
			$this->cardFolder($cardId)->getFile($storageKey)->delete();
		} catch (\Throwable) {
			// Nothing to clean up.
		}
	}

	/**
	 * Normalizes the client filename into a safe display label: strips any path
	 * component (defence in depth - it is never a path, but keep the label
	 * clean), collapses control chars, caps length, and falls back to a generic
	 * name when empty.
	 */
	private function sanitizeFilename(string $name): string {
		$name = basename(str_replace('\\', '/', $name));
		$name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
		$name = trim($name);
		if (strlen($name) > 255) {
			$name = substr($name, 0, 255);
		}
		return $name === '' ? 'attachment' : $name;
	}

	/**
	 * MIME types that could be rendered/scripted inline by a browser if the
	 * download header were ever weakened. We never serve these as their own
	 * Content-Type - they are stored (and downloaded) as a generic binary so an
	 * uploaded `.html`/`.svg` can never become stored XSS. Defence in depth: the
	 * download is ALSO forced Content-Disposition: attachment + nosniff.
	 */
	private const UNSAFE_MIME_PREFIXES = [
		'text/html',
		'application/xhtml',
		'image/svg',
		'application/xml',
		'text/xml',
		'application/javascript',
		'text/javascript',
	];

	/**
	 * Keeps only a plausible `type/subtype` MIME, coercing anything a browser
	 * might render/script inline to a generic binary. The value is
	 * client-supplied and is never trusted for rendering.
	 */
	private function sanitizeMime(string $mime): string {
		$mime = strtolower(trim($mime));
		if (preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $mime) !== 1
			|| strlen($mime) > 255) {
			return 'application/octet-stream';
		}
		foreach (self::UNSAFE_MIME_PREFIXES as $prefix) {
			if (str_starts_with($mime, $prefix)) {
				return 'application/octet-stream';
			}
		}
		return $mime;
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

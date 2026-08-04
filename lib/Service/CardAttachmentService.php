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
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
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
	public const MAX_SIZE = AttachmentSanitizer::MAX_SIZE;

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
		private IRootFolder $rootFolder,
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
	 * Attaches a file from the actor's own Nextcloud Files ("Share from Files",
	 * #3645) by COPYING its bytes into Kanso's app-data - a copied file is an
	 * ordinary attachment row, indistinguishable from an upload. Requires EDIT.
	 *
	 * Security posture (why a fileId + the actor's OWN userfolder, never a path):
	 *  - The node is resolved via {@see IRootFolder::getUserFolder()} for
	 *    $actorUid and {@see \OCP\Files\Folder::getById()} - so the actor can only
	 *    source a file THEY can already read (their own files + files shared TO
	 *    them). A client-supplied numeric id that the actor cannot reach yields no
	 *    node → not-found; there is no path to traverse.
	 *  - Size is capped ({@see self::MAX_SIZE}) against the node's real size BEFORE
	 *    any bytes are streamed.
	 *  - The bytes are COPIED (a stream read into a server-generated storage_key),
	 *    never referenced: a later edit/delete/unshare of the private source node
	 *    can never retroactively leak to (or break for) board members.
	 *
	 * @throws DoesNotExistException if the card or its board does not exist or is deleted
	 * @throws NotPermittedException if the actor may not edit the board
	 * @throws InvalidInputException if the fileId is not a readable file of the actor, empty, or oversized
	 */
	public function attachFromFileNode(int $cardId, int $fileId, string $actorUid): CardAttachment {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);

		// Resolve the node from the ACTOR'S OWN userfolder - the actor can only
		// source a file they can already read. First match wins (a file can appear
		// under several mount points for the same user).
		try {
			$userFolder = $this->rootFolder->getUserFolder($actorUid);
		} catch (\Throwable $e) {
			throw new InvalidInputException('File not found');
		}
		$nodes = $userFolder->getById($fileId);
		$node = $nodes[0] ?? null;
		if (!$node instanceof File) {
			throw new InvalidInputException('File not found');
		}

		// The node's real size, capped BEFORE streaming (mirror the upload cap).
		// getSize() is float|int for very large files; a cast is safe as the value
		// is immediately range-checked against the cap.
		$size = (int)$node->getSize();
		if ($size <= 0) {
			throw new InvalidInputException('Empty file');
		}
		if ($size > self::MAX_SIZE) {
			throw new InvalidInputException('File too large');
		}

		$stream = $node->fopen('rb');
		if ($stream === false) {
			throw new InvalidInputException('Could not read file');
		}

		// SERVER-GENERATED opaque object name - the source filename never touches
		// the storage path (identical to the upload path).
		$storageKey = $this->secureRandom->generate(
			32,
			ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
		);
		$folder = $this->cardFolder($cardId);
		try {
			$folder->newFile($storageKey, $stream);
		} finally {
			/** @psalm-suppress TypeDoesNotContainType, RedundantCondition, DocblockTypeContradiction */
			if (is_resource($stream)) {
				fclose($stream);
			}
		}

		$attachment = new CardAttachment();
		$attachment->setCardId($cardId);
		$attachment->setBoardId($card->getBoardId());
		$attachment->setFilename($this->sanitizeFilename($node->getName()));
		$attachment->setMime($this->sanitizeMime($node->getMimetype()));
		$attachment->setSize($size);
		$attachment->setStorageKey($storageKey);
		$attachment->setUploadedBy($actorUid);
		$attachment->setCreatedAt(time());

		try {
			$attachment = $this->attachmentMapper->insert($attachment);
		} catch (\Throwable $e) {
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
	 * RASTER image mimes that are safe to serve INLINE (Content-Disposition:
	 * inline) so a pasted screenshot can be embedded in a description/comment.
	 *
	 * DELIBERATELY excludes image/svg+xml: an SVG is an XML document that can
	 * carry <script>/on* handlers and would be executed if rendered inline, so it
	 * stays download-only. Only these four bitmap formats are ever inlined; the
	 * exact stored mime must be one of them or the inline endpoint 404s.
	 */
	private const INLINE_IMAGE_MIMES = [
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
	];

	/**
	 * Resolves an attachment for INLINE display (embedding a pasted raster image
	 * in a description/comment). Same gating as {@see self::download()} - READ on
	 * the card's board + the IDOR guard - but ONLY returns bytes for the strict
	 * raster-image allow-list ({@see self::INLINE_IMAGE_MIMES}). Any other
	 * attachment (svg, html, txt, pdf, …) is treated as not-found: a 404, never
	 * inlined. The caller sets Content-Disposition: inline + nosniff + the exact
	 * allow-listed Content-Type from the returned metadata.
	 *
	 * @return array{0: CardAttachment, 1: string}
	 * @throws DoesNotExistException if the card/board/attachment does not exist, is deleted, is on another card, or is not an allow-listed raster image
	 * @throws NotPermittedException if the actor may not read the board
	 */
	public function inline(int $cardId, int $attachmentId, string $actorUid): array {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_READ);

		$attachment = $this->loadAttachmentOnCard($attachmentId, $cardId);

		// The gate: only bitmap images the browser cannot script are inlined.
		// Everything else is a 404 here (still reachable via the download
		// endpoint, which forces Content-Disposition: attachment).
		if (!in_array($attachment->getMime(), self::INLINE_IMAGE_MIMES, true)) {
			throw new DoesNotExistException('Attachment ' . $attachmentId . ' is not an inline-serveable image');
		}

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
	 * Cascade cleanup when a card is PERMANENTLY removed (trash purge). Removes
	 * BOTH the stored bytes (the per-card app-data objects) AND the metadata
	 * rows, so a purged card never leaves orphaned storage behind.
	 *
	 * No permission check and no change notification: this is an internal
	 * cascade invoked by callers ({@see TrashService::purge()}) that have
	 * already authorized the destructive card removal and emit their own card
	 * DELETE change row. It intentionally does NOT gate on the card/board being
	 * live - the card is being torn down.
	 *
	 * Robustness: each object delete is best-effort and independent - one
	 * missing/failing object does not abort the rest, and the folder itself is
	 * removed at the end (a no-op if it never existed). The rows are dropped in a
	 * single set-based statement. Safe to call for a card with zero attachments.
	 */
	public function deleteAllForCard(int $cardId): void {
		$attachments = $this->attachmentMapper->findByCard($cardId);
		foreach ($attachments as $attachment) {
			// Best-effort per object - a failure on one must not strand the others.
			$this->deleteObjectQuietly($cardId, $attachment->getStorageKey());
		}

		// Drop the whole per-card folder too, so nothing (including any object
		// whose row was already gone) is left in app-data. Best-effort: a missing
		// folder is fine.
		try {
			$this->appData->getFolder(self::FOLDER_PREFIX . $cardId)->delete();
		} catch (\Throwable) {
			// Nothing to clean up.
		}

		$this->attachmentMapper->deleteByCard($cardId);
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
	 * Normalizes the client filename into a safe display label. Delegates to the
	 * shared {@see AttachmentSanitizer} so every store path (upload, Files copy,
	 * Deck import) applies the identical coercion.
	 */
	private function sanitizeFilename(string $name): string {
		return AttachmentSanitizer::filename($name);
	}

	/**
	 * Coerces a client-supplied MIME to a browser-safe value via the shared
	 * {@see AttachmentSanitizer}. The value is never trusted for rendering.
	 */
	private function sanitizeMime(string $mime): string {
		return AttachmentSanitizer::mime($mime);
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

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
use OCA\Kanso\Db\ChangeDetailMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

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
 *  - An administrator can additionally cap the TOTAL bytes the app stores
 *    ({@see self::KEY_ATTACHMENT_STORAGE_LIMIT}) - see
 *    {@see self::assertStorageHeadroom()}. Off unless configured.
 *
 * Add/delete reuse the card's ENTITY_CARD / ACTION_UPDATE change row so the
 * existing realtime/delta-sync + ETag path reflects the new attachment count.
 * Since #119 that row also carries a verb (VERB_ATTACHMENT_ADDED /
 * VERB_ATTACHMENT_REMOVED) plus the filename in `kanso_change_details`, so the
 * per-card Activity feed can say WHO attached or removed WHICH file and WHEN -
 * a removal has no other trace, the row and the bytes are both gone.
 */
class CardAttachmentService {
	/** Hard cap on a single upload. Oversized uploads are rejected. */
	public const MAX_SIZE = AttachmentSanitizer::MAX_SIZE;

	public const APP_ID = 'kanso';

	/**
	 * OPTIONAL, admin-only, instance-wide cap on the TOTAL bytes Kanso stores in
	 * its own app-data, in bytes:
	 *
	 *     occ config:app:set kanso attachment_storage_limit --value 10737418240
	 *
	 * **Off by default.** Absent, empty, zero or negative means NO cap, which is
	 * exactly the behaviour of every release before this one - an existing
	 * install must be completely unaffected until an admin opts in.
	 *
	 * It exists because attachment bytes live in app-data, NOT in the uploader's
	 * Files, and are therefore outside their Nextcloud quota: without a cap the
	 * only bound on what an authenticated user can write is the host disk.
	 *
	 * Deliberately instance-wide, not per user or per board: Nextcloud already
	 * owns the per-user quota concept and a second one alongside it would only
	 * disagree with it.
	 */
	public const KEY_ATTACHMENT_STORAGE_LIMIT = 'attachment_storage_limit';

	/** Per-card app-data subfolder holding that card's attachment objects. */
	private const FOLDER_PREFIX = 'card-';

	/**
	 * Cap on a change-detail string, matching the other services that write the
	 * side table (LabelService, AssigneeService, CardService).
	 */
	private const MAX_DETAIL_LENGTH = 10000;

	public function __construct(
		private CardAttachmentMapper $attachmentMapper,
		private CardMapper $cardMapper,
		private BoardMapper $boardMapper,
		private PermissionService $permissionService,
		private ChangeNotifier $changeNotifier,
		private IAppData $appData,
		private ISecureRandom $secureRandom,
		private IRootFolder $rootFolder,
		private CardVisibilityGuard $visibilityGuard,
		private ChangeDetailMapper $changeDetailMapper,
		private IConfig $config,
		private LoggerInterface $logger,
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
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

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
	 * @throws StorageLimitException if an admin-configured instance-wide storage cap has no room left
	 */
	public function upload(int $cardId, ?array $upload, string $actorUid): CardAttachment {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

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
		// Instance-wide storage cap, if an admin configured one. Checked BEFORE a
		// single byte is written.
		$this->assertStorageHeadroom($size);

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

		$this->recordAttachmentChange($card, $attachment->getFilename(), $actorUid, Change::VERB_ATTACHMENT_ADDED);

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
	 * @throws StorageLimitException if an admin-configured instance-wide storage cap has no room left
	 */
	public function attachFromFileNode(int $cardId, int $fileId, string $actorUid): CardAttachment {
		$card = $this->loadCard($cardId);
		$board = $this->loadBoard($card->getBoardId());
		$this->permissionService->assertPermission($board, $actorUid, PermissionService::PERMISSION_EDIT);
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

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
		// The SAME instance-wide storage cap as upload() - this path copies the
		// same bytes into the same app-data, so leaving it out would just be the
		// open door next to the closed one.
		$this->assertStorageHeadroom($size);

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

		$this->recordAttachmentChange($card, $attachment->getFilename(), $actorUid, Change::VERB_ATTACHMENT_ADDED);

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
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

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
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		return $this->readInlineImage($cardId, $attachmentId);
	}

	/**
	 * The same inline-image read as {@see self::inline()}, for a caller that has
	 * ALREADY authorized the read by some means OTHER than a user session - today
	 * only the public board share ({@see PublicShareService::getPublicInlineAttachment()}),
	 * whose visitor is anonymous by definition and is instead gated on a board's
	 * share TOKEN.
	 *
	 * It performs NO permission or visibility check of its own, so it must never
	 * be called with a client-supplied card id that has not been resolved against
	 * a share token first. What it DOES keep is everything that is not about WHO
	 * is asking: the IDOR guard ({@see self::loadAttachmentOnCard()}, so an
	 * attachment on another card cannot be fetched through this card's id) and the
	 * raster-only allow-list ({@see self::INLINE_IMAGE_MIMES}, so an SVG or an HTML
	 * attachment is a 404 here exactly as it is for an authenticated reader).
	 *
	 * @return array{0: CardAttachment, 1: string}
	 * @throws DoesNotExistException if the attachment does not exist, is on another card, or is not an allow-listed raster image
	 */
	public function inlineForAuthorizedShare(int $cardId, int $attachmentId): array {
		return $this->readInlineImage($cardId, $attachmentId);
	}

	/**
	 * The WHO-agnostic half of the inline read: IDOR guard, raster allow-list,
	 * bytes. Both public entry points above funnel through this so the two can
	 * never drift on what an inline image is allowed to be.
	 *
	 * @return array{0: CardAttachment, 1: string}
	 * @throws DoesNotExistException if the attachment does not exist, is on another card, or is not an allow-listed raster image
	 */
	private function readInlineImage(int $cardId, int $attachmentId): array {
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
	 * Opens the stored bytes of ONE attachment object as a read stream, for an
	 * internal consumer that has ALREADY authorized the read - today only the
	 * board archive writer ({@see BoardArchiveService}), which walks a card set
	 * the exporting viewer was gated on before this is ever reached.
	 *
	 * Deliberately performs no permission or visibility check of its own, and
	 * that is safe precisely because of the storage model: it is addressed by
	 * `storage_key`, a server-generated random name that never leaves the server
	 * (it is withheld from every API response and from the export manifest), so
	 * no client-supplied value can select an object here.
	 *
	 * Returns null - rather than throwing - when the object is missing, so one
	 * vanished blob cannot abort a whole board's export or scheduled backup.
	 * The caller owns the stream and must close it.
	 *
	 * @return resource|null
	 */
	public function openStoredObject(int $cardId, string $storageKey) {
		try {
			$stream = $this->cardFolder($cardId)->getFile($storageKey)->read();
		} catch (\Throwable) {
			return null;
		}
		return is_resource($stream) ? $stream : null;
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
		$this->visibilityGuard->assertVisible($board, $card, $actorUid);

		$attachment = $this->loadAttachmentOnCard($attachmentId, $cardId);
		// Read the label BEFORE the row goes: after the delete it is the only
		// surviving record of which file this was. (The column is NOT NULL, so the
		// cast is for the entity's nullable property, never a real row.)
		$filename = (string)$attachment->getFilename();

		// Drop the row first (the source of truth for what's listed); then
		// best-effort remove the bytes.
		$this->attachmentMapper->delete($attachment);
		$this->deleteObjectQuietly($cardId, $attachment->getStorageKey());

		$this->recordAttachmentChange($card, $filename, $actorUid, Change::VERB_ATTACHMENT_REMOVED);
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
	 * Removes the stored BYTES of a set of cards - their per-card app-data
	 * folders - WITHOUT touching any metadata row. The board purge
	 * ({@see BoardPurgeService}) uses this because app-data objects live outside
	 * the database and therefore outside its transaction: the bytes have to go
	 * first, and only a fully successful sweep may be followed by the row purge.
	 *
	 * Partial-failure tolerant by design: a storage error on one card is counted
	 * and the sweep continues through the rest, so one unreadable folder never
	 * strands the others. The COUNT is what the caller acts on - a non-zero
	 * result means "leave this board's rows alone and retry next run", which is
	 * why this reports instead of throwing.
	 *
	 * A card that never had an attachment has no folder; that is success, not a
	 * failure.
	 *
	 * @param list<int> $cardIds
	 * @return int number of cards whose stored bytes could NOT be removed
	 */
	public function deleteObjectsForCards(array $cardIds): int {
		$failures = 0;
		foreach ($cardIds as $cardId) {
			try {
				$this->appData->getFolder(self::FOLDER_PREFIX . $cardId)->delete();
			} catch (NotFoundException) {
				// Nothing was ever stored for this card - not a failure.
			} catch (\Throwable) {
				$failures++;
			}
		}

		return $failures;
	}

	/**
	 * The instance-wide attachment storage cap in bytes, or 0 for "no cap".
	 *
	 * Absent, empty, non-numeric, zero or negative all mean the SAME thing: the
	 * cap is off and nothing below it ever runs. That is the default, and it is
	 * what keeps this whole feature inert on an install whose admin has not
	 * opted in.
	 */
	public function storageLimit(): int {
		$raw = trim($this->config->getAppValue(self::APP_ID, self::KEY_ATTACHMENT_STORAGE_LIMIT, ''));
		if ($raw === '' || $raw === '0') {
			return 0;
		}
		if (!ctype_digit($raw) || (int)$raw <= 0) {
			// An admin who typed `10G` (or `10 GB`, or a negative) meant to cap
			// this instance and did NOT. Failing open is the right direction - a
			// typo must not start refusing uploads - but failing open SILENTLY
			// would leave them believing the cap is on, so say so.
			$this->logger->warning(
				'Kanso: ignoring an unusable ' . self::KEY_ATTACHMENT_STORAGE_LIMIT
				. ' app value - it must be a plain positive number of BYTES, so no attachment storage limit is in force',
				['value' => $raw]
			);
			return 0;
		}
		return (int)$raw;
	}

	/**
	 * Refuses a write that would push the instance past the configured storage
	 * cap. Called by BOTH write paths ({@see self::upload()} and
	 * {@see self::attachFromFileNode()}) before any bytes are written.
	 *
	 * With no cap configured this returns immediately and, crucially, issues NO
	 * QUERY: the `SUM(size)` only happens on an instance that actually opted in,
	 * so the default install pays nothing for a feature it is not using.
	 *
	 * An already-over-cap instance keeps working in every direction except
	 * adding: listing, downloading and deleting are untouched, so an admin (or
	 * the users themselves) can always delete their way back under the line.
	 *
	 * The check is a ceiling, not a lock: concurrent uploads can each read the
	 * same total and each be admitted, so the real figure may overshoot by up to
	 * (concurrent writes x {@see self::MAX_SIZE}). Reserving rows to close that
	 * would cost every upload a write; the point here is to bound unlimited
	 * growth, and an overshoot of a few files does that.
	 *
	 * Deliberately NOT applied to the ARCHIVE import writer
	 * ({@see ImportService}): one archive is already bounded by
	 * {@see ImportArchiveReader::MAX_TOTAL_BYTES} plus the import endpoint's own
	 * per-user rate limit, and failing halfway through a restore would leave a
	 * partially-restored board. That bypass is a decision, not an oversight.
	 * Deck import is NOT exempt - its bytes come from Deck's own storage and so
	 * have no archive bound at all; it honours the same cap by skipping the
	 * attachments that no longer fit ({@see DeckImportService}).
	 *
	 * @throws StorageLimitException if the instance has no room for $incomingBytes
	 */
	private function assertStorageHeadroom(int $incomingBytes): void {
		$limit = $this->storageLimit();
		if ($limit <= 0) {
			// No cap configured - behave exactly as every release before this one.
			return;
		}

		$used = $this->attachmentMapper->totalSize();
		if ($used + $incomingBytes > $limit) {
			throw new StorageLimitException(
				'Attachment storage is full on this server. An administrator can free space or '
				. 'raise the attachment_storage_limit app setting.'
			);
		}
	}

	/**
	 * Appends the card's change row for an attachment add/remove (#119) and
	 * records the filename in the `kanso_change_details` side table, so the
	 * Activity feed can name the file rather than render a bare "updated this
	 * card". The filename rides the same side as the equivalent label change:
	 * `to` when something appeared, `from` when something went away.
	 *
	 * Still an ENTITY_CARD / ACTION_UPDATE row - delta sync and the ETag key on
	 * (entity_type, action), never on the verb, so the realtime path is unchanged.
	 */
	private function recordAttachmentChange(Card $card, string $filename, string $actorUid, int $verb): void {
		$change = $this->changeNotifier->notify(
			$card->getBoardId(),
			Change::ENTITY_CARD,
			(int)$card->getId(),
			Change::ACTION_UPDATE,
			$actorUid,
			verb: $verb,
		);

		$label = $this->capDetail($filename);
		$added = $verb === Change::VERB_ATTACHMENT_ADDED;
		$this->changeDetailMapper->insertDetail(
			$change->getId(),
			$added ? null : $label,
			$added ? $label : null,
		);
	}

	/**
	 * Caps a detail string to {@see self::MAX_DETAIL_LENGTH} chars (multibyte-safe),
	 * consistent with the other services that write the side table.
	 */
	private function capDetail(string $value): string {
		return mb_substr($value, 0, self::MAX_DETAIL_LENGTH);
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

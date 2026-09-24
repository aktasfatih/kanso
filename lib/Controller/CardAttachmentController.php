<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\CardAttachment;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Card file-attachment endpoints: list (READ), upload (EDIT, multipart),
 * download (READ, streams the bytes from Kanso's app-data with a
 * Content-Disposition: attachment header) and delete (EDIT).
 */
class CardAttachmentController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardAttachmentService $attachmentService,
		private IUserManager $userManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			// One display-name cache across the list: a card's attachments are
			// usually all from the same one or two people.
			$nameCache = [];
			$out = [];
			foreach ($this->attachmentService->listForCard($cardId, $this->currentUserId()) as $attachment) {
				$out[] = $this->serialize($attachment, $nameCache);
			}
			return new JSONResponse($out);
		});
	}

	/**
	 * Every attachment on a BOARD the caller may see, newest first (#10670) -
	 * what the board's "All attachments" view lists, so files are reachable
	 * without opening each card in turn.
	 *
	 * Board-READ gated, and scoped to the caller's card visibility inside the
	 * query: a card hidden from them contributes nothing here. The page is
	 * hard-capped ({@see CardAttachmentService::BOARD_PAGE_LIMIT}); the response
	 * is {items, total, capped} so a partial page says so rather than pretending
	 * to be the whole board.
	 *
	 * Each item is the attachment's own metadata plus the uploader's display name
	 * and the owning card's title - enough to list a file and jump to its card.
	 * Downloads go through the existing per-card endpoint; no second byte path.
	 */
	#[NoAdminRequired]
	public function board(int $boardId, int $limit = CardAttachmentService::BOARD_PAGE_LIMIT, int $offset = 0): JSONResponse {
		return $this->respond(function () use ($boardId, $limit, $offset): JSONResponse {
			$page = $this->attachmentService->listForBoard($boardId, $this->currentUserId(), $limit, $offset);

			// One display-name cache across the page: a board's files usually come
			// from a handful of people.
			$nameCache = [];
			$items = [];
			foreach ($page['items'] as $row) {
				$items[] = $this->serialize($row['attachment'], $nameCache)
					+ ['cardTitle' => $row['cardTitle']];
			}

			return new JSONResponse([
				'items' => $items,
				'total' => $page['total'],
				'capped' => $page['capped'],
			]);
		});
	}

	/**
	 * Uploads one file onto a card (EDIT, multipart). Per-user rate limited: every
	 * accepted upload writes up to
	 * {@see \OCA\Kanso\Service\AttachmentSanitizer::MAX_SIZE} bytes into the app's
	 * own app-data, and the only other bound on that is the per-file cap. The limit
	 * is deliberately looser than board import's - bulk-attaching a few dozen
	 * screenshots in one sitting is ordinary use, and the e2e suite uploads as one
	 * shared admin across parallel workers - while still bounding a scripted loop.
	 * It bounds request COUNT, not bytes; the aggregate BYTES are bounded
	 * separately, and only where an admin asked for it, by the optional
	 * instance-wide cap ({@see CardAttachmentService::KEY_ATTACHMENT_STORAGE_LIMIT}),
	 * which answers 413 through {@see ApiErrorTrait} when it is full.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function create(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			$upload = $this->request->getUploadedFile('file');
			return new JSONResponse($this->serialize(
				$this->attachmentService->upload($cardId, $upload, $this->currentUserId())
			));
		});
	}

	/**
	 * "Share from Files" (#3645): attach a file from the actor's own Nextcloud
	 * Files by COPYING its bytes into the card. Body: {fileId}. EDIT-gated; the
	 * node is resolved only through the actor's own userfolder (never a
	 * client-supplied path), size-capped before streaming.
	 *
	 * Carries the SAME per-user rate limit as {@see self::create()}: it copies the
	 * same bytes into the same app-data, so leaving it unlimited would just be the
	 * open door next to the closed one.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function createFromFile(int $cardId, int $fileId = 0): JSONResponse {
		return $this->respond(function () use ($cardId, $fileId): JSONResponse {
			return new JSONResponse($this->serialize(
				$this->attachmentService->attachFromFileNode($cardId, $fileId, $this->currentUserId())
			));
		});
	}

	/**
	 * Streams an attachment's bytes. Always Content-Disposition: attachment
	 * (DownloadResponse) so an untrusted file is never rendered inline.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $cardId, int $attachmentId): DataDownloadResponse|JSONResponse {
		try {
			[$attachment, $bytes] = $this->attachmentService->download(
				$cardId,
				$attachmentId,
				$this->currentUserId()
			);
			$response = new DataDownloadResponse(
				$bytes,
				$attachment->getFilename(),
				$attachment->getMime()
			);
			// Defence in depth: force a download (never render an untrusted file
			// inline) and stop MIME sniffing, independent of DownloadResponse's
			// own defaults. The filename is quoted and stripped of characters that
			// could break out of the header value.
			$safeName = str_replace(['"', '\\', "\r", "\n"], '', $attachment->getFilename());
			$response->addHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"');
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			return $response;
		} catch (\Throwable $e) {
			// Reuse the shared error mapping (403/404/400) for the failure paths;
			// on success we already returned the download above.
			return $this->respond(function () use ($e): JSONResponse {
				throw $e;
			});
		}
	}

	/**
	 * Serves an attachment's bytes INLINE (Content-Disposition: inline) so a
	 * pasted raster image can render in a description/comment. Board-READ gated +
	 * IDOR-guarded exactly like download(), but the service only returns bytes for
	 * a strict raster-image allow-list (png/jpeg/gif/webp) - any other attachment
	 * (svg/html/txt/pdf/…) is a 404 here and stays download-only.
	 *
	 * The Content-Type is set from the allow-listed mime (never client-echoed) and
	 * X-Content-Type-Options: nosniff is kept so the browser cannot re-interpret
	 * the bytes as a scriptable type.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inline(int $cardId, int $attachmentId): DataDisplayResponse|JSONResponse {
		try {
			[$attachment, $bytes] = $this->attachmentService->inline(
				$cardId,
				$attachmentId,
				$this->currentUserId()
			);
			$response = new DataDisplayResponse(
				$bytes,
				\OCP\AppFramework\Http::STATUS_OK,
				['Content-Type' => $attachment->getMime()]
			);
			$response->addHeader('Content-Disposition', 'inline');
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			return $response;
		} catch (\Throwable $e) {
			// Reuse the shared error mapping (403/404/400) for the failure paths.
			return $this->respond(function () use ($e): JSONResponse {
				throw $e;
			});
		}
	}

	#[NoAdminRequired]
	public function destroy(int $cardId, int $attachmentId): JSONResponse {
		return $this->respond(function () use ($cardId, $attachmentId): JSONResponse {
			$this->attachmentService->delete($cardId, $attachmentId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * The wire shape of one attachment: the entity's own metadata plus the
	 * uploader's display name (#119), so the card can credit a person rather than
	 * print a raw uid. The uid itself stays in the payload - the avatar endpoint
	 * is keyed on it.
	 *
	 * @param array<string, string> $nameCache uid => display name, reused across a list
	 * @return array<string, mixed>
	 */
	private function serialize(CardAttachment $attachment, array &$nameCache = []): array {
		return $attachment->jsonSerialize()
			+ ['uploadedByName' => $this->displayName((string)$attachment->getUploadedBy(), $nameCache)];
	}

	/**
	 * @param array<string, string> $nameCache uid => display name, reused across a list
	 */
	private function displayName(string $uid, array &$nameCache): string {
		if (!isset($nameCache[$uid])) {
			$user = $this->userManager->get($uid);
			$nameCache[$uid] = $user !== null ? $user->getDisplayName() : $uid;
		}
		return $nameCache[$uid];
	}

	/**
	 * @throws NotPermittedException if there is no user session
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotPermittedException('No authenticated user');
		}
		return $user->getUID();
	}
}

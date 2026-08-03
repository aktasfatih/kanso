<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->attachmentService->listForCard($cardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function create(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			$upload = $this->request->getUploadedFile('file');
			return new JSONResponse(
				$this->attachmentService->upload($cardId, $upload, $this->currentUserId())
			);
		});
	}

	/**
	 * Streams an attachment's bytes. Always Content-Disposition: attachment
	 * (DownloadResponse) so an untrusted file is never rendered inline.
	 */
	#[NoAdminRequired]
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

	#[NoAdminRequired]
	public function destroy(int $cardId, int $attachmentId): JSONResponse {
		return $this->respond(function () use ($cardId, $attachmentId): JSONResponse {
			$this->attachmentService->delete($cardId, $attachmentId, $this->currentUserId());
			return new JSONResponse([]);
		});
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

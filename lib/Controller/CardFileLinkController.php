<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardFileLinkService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Card file-link endpoints (KANSO-307): list a card's live Nextcloud Files
 * links, link a node by fileId, and remove a link. The service holds the
 * access model; this layer only maps exceptions to statuses.
 */
class CardFileLinkController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardFileLinkService $fileLinkService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->fileLinkService->listForCard($cardId, $this->currentUserId())
			);
		});
	}

	/**
	 * Body: {fileId}. Rate-limited like the copy-from-Files endpoint: each call
	 * resolves the id against the actor's userfolder, so an unthrottled loop
	 * would make it a cheap fileId probe.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function create(int $cardId, int $fileId = 0): JSONResponse {
		return $this->respond(function () use ($cardId, $fileId): JSONResponse {
			return new JSONResponse(
				$this->fileLinkService->addLink($cardId, $fileId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $cardId, int $linkId): JSONResponse {
		return $this->respond(function () use ($cardId, $linkId): JSONResponse {
			$this->fileLinkService->deleteLink($cardId, $linkId, $this->currentUserId());
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

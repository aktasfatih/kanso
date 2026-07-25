<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardLinkService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Card GitHub-link endpoints: list (with a throttled state refresh), attach a
 * URL, and remove a link.
 */
class CardLinkController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardLinkService $cardLinkService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->cardLinkService->listForCard($cardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function create(int $cardId, string $url = ''): JSONResponse {
		return $this->respond(function () use ($cardId, $url): JSONResponse {
			return new JSONResponse(
				$this->cardLinkService->addLink($cardId, $url, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $cardId, int $linkId): JSONResponse {
		return $this->respond(function () use ($cardId, $linkId): JSONResponse {
			$this->cardLinkService->deleteLink($cardId, $linkId, $this->currentUserId());
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

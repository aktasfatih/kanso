<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Fragment-free card deep link (#3744): `/apps/kanso/card/{id}` is a SERVER
 * route, so the target survives the login round-trip - unlike the hash routes
 * (`#/board/…/card/…`), whose fragment the login redirect drops, which is
 * exactly how the guests who most need an emailed link used to land on the
 * home page. Email/bell/ICS card links all point here now.
 *
 * `#[NoAdminRequired]` is load-bearing: without it a non-admin (and every
 * Guests-app guest) gets a 403 instead of the card. No `@` ever appears in
 * the path or query ({id} is numeric), so the Nextcloud login controller
 * never drops the redirect target.
 *
 * Authorization mirrors the card API ({@see CardService::find}: board READ +
 * card visibility). Any failure - missing card, hidden card, non-member -
 * renders the SAME 404 page, so the route is not an existence oracle. An
 * unauthenticated request never reaches here (no #[PublicPage]): Nextcloud
 * sends it through the login flow and back.
 */
class DeepLinkController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private CardService $cardService,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function card(int $id): TemplateResponse {
		if ($this->userId === null) {
			// Unreachable in practice (the auth middleware redirects first);
			// defense-in-depth for a session that evaporated mid-request.
			return $this->notFound();
		}

		try {
			$card = $this->cardService->find($id, $this->userId);
		} catch (DoesNotExistException|NotPermittedException) {
			// One shape for every failure: a hidden card, a foreign board and
			// a card that never existed must be indistinguishable.
			return $this->notFound();
		}

		// Hand the target to the SPA via initial state and render the normal
		// app shell; the router reads `openCard` on boot and opens the modal.
		$this->initialState->provideInitialState('openCard', [
			'boardId' => $card->getBoardId(),
			'cardId' => $card->getId(),
		]);
		$this->addMainScript();
		return new TemplateResponse(Application::APP_ID, 'main');
	}

	/**
	 * Queues the SPA bundle for the app shell (same script PageController
	 * loads). A seam: Util::addScript needs the full server (\OC) at runtime,
	 * so the unit test overrides this no-op-style.
	 */
	protected function addMainScript(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
	}

	private function notFound(): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'card-notfound', [
			'appUrl' => $this->urlGenerator->linkToRoute('kanso.page.index'),
		]);
		$response->setStatus(Http::STATUS_NOT_FOUND);
		return $response;
	}
}

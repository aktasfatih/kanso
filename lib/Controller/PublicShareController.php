<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PublicShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * Public / read-only board share links (#3531).
 *
 * MANAGE-only config endpoints (config/enable/disable) mint, rotate and revoke a
 * board's public token, and TWO unauthenticated endpoints serve the shared board:
 *  - {@see self::data()} returns the STRIPPED JSON payload for a token, and
 *  - {@see self::show()} renders the read-only SPA shell for a token.
 *
 * Both public endpoints are `#[PublicPage] #[NoCSRFRequired]` with
 * `#[BruteForceProtection]`; an unknown/disabled/rotated/expired token is a
 * throttled 404 so the token space can't be enumerated. The config endpoints are
 * `#[NoAdminRequired]` (a normal authenticated route) and are gated by MANAGE in
 * the service - they are NEVER public.
 */
class PublicShareController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private PublicShareService $publicShareService,
	) {
		parent::__construct($appName, $request);
	}

	// ── MANAGE config (authenticated) ─────────────────────────────────────────

	#[NoAdminRequired]
	public function config(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->publicShareService->getConfig($id, $this->currentUserId())
			);
		});
	}

	/**
	 * Enable the public link (or rotate an existing one) - both mint a fresh
	 * token, invalidating any previously-issued link.
	 */
	#[NoAdminRequired]
	public function enable(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->publicShareService->enable($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function disable(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->publicShareService->disable($id, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	// ── Public read-only (unauthenticated) ────────────────────────────────────

	/**
	 * The STRIPPED read-only board payload for a token. No session. An unknown /
	 * disabled / rotated / expired token is a throttled 404 so the token space
	 * can't be brute-forced or enumerated (same failure shape for every reason).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'kansoPublicShare')]
	public function data(string $token): JSONResponse {
		try {
			return new JSONResponse($this->publicShareService->getPublicBoard($token));
		} catch (DoesNotExistException) {
			$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'kansoPublicShare']);
			return $response;
		}
	}

	/**
	 * Renders the read-only SPA shell for a public board. No session, no auth
	 * chrome. The token is validated here too (throttled 404 on a bad token) so
	 * the page never loads for an invalid link; the client then reads
	 * {@see self::data()} for the payload.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'kansoPublicShare')]
	public function show(string $token): Http\Response {
		try {
			// Validate the token before rendering anything - a bad token must not
			// even get the app shell, and this is throttled like the data route.
			// A lightweight existence check (not a full payload build) so the
			// unauthenticated page route can't be used to amplify board queries;
			// the client fetches the real payload via data() once the shell loads.
			$this->publicShareService->assertTokenValid($token);
		} catch (DoesNotExistException) {
			$response = new TemplateResponse(
				Application::APP_ID,
				'public-notfound',
				[],
				TemplateResponse::RENDER_AS_GUEST
			);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'kansoPublicShare']);
			return $response;
		}

		Util::addScript(Application::APP_ID, Application::APP_ID . '-public');
		// RENDER_AS_PUBLIC: the guest/public layout - no authenticated app
		// navigation, no user menu, no board chrome that assumes a session.
		return new TemplateResponse(
			Application::APP_ID,
			'public',
			['token' => $token],
			TemplateResponse::RENDER_AS_PUBLIC
		);
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

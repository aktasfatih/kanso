<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CalendarFeedService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * iCal / ICS read-only feed of card due dates (#3541).
 *
 * MANAGE-only config endpoints (config/enable/disable) mint, rotate and revoke a
 * board's feed token, and ONE unauthenticated endpoint {@see self::feed()} serves
 * the read-only VCALENDAR for a token as `text/calendar`.
 *
 * The public feed endpoint is `#[PublicPage] #[NoCSRFRequired]` with
 * `#[BruteForceProtection]`; an unknown/disabled/rotated token is a throttled 404
 * so the token space can't be enumerated. The config endpoints are
 * `#[NoAdminRequired]` (a normal authenticated route) and are gated by MANAGE in
 * the service - they are NEVER public.
 *
 * This is deliberately READ-ONLY: there is no write-back, no VTODO round-trip and
 * no CalDAV backend here - that is the separate full-sync feature (#3534).
 */
class CalendarFeedController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CalendarFeedService $calendarFeedService,
	) {
		parent::__construct($appName, $request);
	}

	// ── MANAGE config (authenticated) ─────────────────────────────────────────

	#[NoAdminRequired]
	public function config(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->calendarFeedService->getConfig($id, $this->currentUserId())
			);
		});
	}

	/**
	 * Enable the feed (or rotate an existing one) - both mint a fresh token,
	 * invalidating any previously-issued feed URL.
	 */
	#[NoAdminRequired]
	public function enable(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->calendarFeedService->enable($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function disable(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->calendarFeedService->disable($id, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	// ── Public read-only (unauthenticated) ────────────────────────────────────

	/**
	 * The read-only ICS body for a token, served as `text/calendar`. No session.
	 * An unknown / disabled / rotated token is a throttled 404 so the token space
	 * can't be brute-forced or enumerated (same failure shape for every reason).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'kansoCalendarFeed')]
	public function feed(string $token): Http\Response {
		try {
			$ics = $this->calendarFeedService->renderFeed($token);
		} catch (DoesNotExistException) {
			$response = new DataDisplayResponse('', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
			$response->throttle(['action' => 'kansoCalendarFeed']);
			return $response;
		}

		return new DataDisplayResponse(
			$ics,
			Http::STATUS_OK,
			['Content-Type' => 'text/calendar; charset=UTF-8'],
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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardCalendarService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user "show this board in my calendar" toggle for the read-only CalDAV
 * VTODO calendars (#3534 / issue #49).
 *
 * This is a PERSONAL preference, not a board setting: it only changes whether the
 * board appears in the calling user's OWN CalDAV account, never anyone else's, and
 * any board member may set it (no MANAGE needed). Boards sync by default; this
 * lets a user hide the noisy ones. Stored in the NC user config via
 * {@see CardCalendarService}. Distinct from the MANAGE-only, board-wide ICS feed
 * ({@see CalendarFeedController}).
 */
class CalendarSyncController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardCalendarService $cardCalendarService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The calling user's calendar preferences for this board.
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse($this->cardCalendarService->preferencesForUser($this->currentUserId(), $id));
		});
	}

	/**
	 * Update personal calendar preferences and return their resulting state.
	 */
	#[NoAdminRequired]
	public function update(int $id, ?bool $enabled = null, ?bool $assignedOnly = null): JSONResponse {
		return $this->respond(function () use ($id, $enabled, $assignedOnly): JSONResponse {
			$uid = $this->currentUserId();
			$this->cardCalendarService->setPreferencesForUser($uid, $id, $enabled, $assignedOnly);
			return new JSONResponse($this->cardCalendarService->preferencesForUser($uid, $id));
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

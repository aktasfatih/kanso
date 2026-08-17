<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ReminderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Personal "remind me" endpoints (#3816). Every reminder is private to the
 * caller: scheduling needs READ + card visibility (enforced in
 * {@see ReminderService}), listing and cancelling only ever touch the caller's
 * own rows. `remindAt` is a unix instant that must be in the future; an
 * optional `commentId` scopes the reminder to a specific comment.
 */
class ReminderController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ReminderService $reminderService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The caller's own pending reminders on the card, soonest first.
	 */
	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse($this->reminderService->listMineForCard($cardId, $this->currentUserId()));
		});
	}

	/**
	 * Schedules a personal reminder on the card. Body: {remindAt: <unix ts>,
	 * commentId?: <int>}. Returns the created reminder.
	 */
	#[NoAdminRequired]
	public function create(int $cardId, int $remindAt = 0, ?int $commentId = null): JSONResponse {
		return $this->respond(function () use ($cardId, $remindAt, $commentId): JSONResponse {
			$reminder = $this->reminderService->schedule($cardId, $this->currentUserId(), $remindAt, $commentId);
			return new JSONResponse($reminder->jsonSerialize());
		});
	}

	/**
	 * Cancels one of the caller's own reminders by id. Idempotent - cancelling
	 * an absent / foreign reminder succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function destroy(int $cardId, int $reminderId): JSONResponse {
		return $this->respond(function () use ($cardId, $reminderId): JSONResponse {
			$this->reminderService->cancel($cardId, $reminderId, $this->currentUserId());
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

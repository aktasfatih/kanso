<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardTimeEntryService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Card time-tracking endpoints (#3536): list (READ), add a manual entry (EDIT)
 * and delete an entry (EDIT). Manual entries only - no running-timer state.
 */
class CardTimeEntryController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardTimeEntryService $timeEntryService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->timeEntryService->listForCard($cardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function create(int $cardId, int $seconds = 0, ?string $note = null): JSONResponse {
		return $this->respond(function () use ($cardId, $seconds, $note): JSONResponse {
			return new JSONResponse(
				$this->timeEntryService->add($cardId, $seconds, $note, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $cardId, int $entryId): JSONResponse {
		return $this->respond(function () use ($cardId, $entryId): JSONResponse {
			$this->timeEntryService->delete($cardId, $entryId, $this->currentUserId());
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

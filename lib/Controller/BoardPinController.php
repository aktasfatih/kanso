<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\BoardPinService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user board pinning (#3632). Pinning is the curation mechanism that drives
 * BOTH the boards-page "Pinned" section AND the left-sidebar nav. Every endpoint
 * is scoped to the session user via {@see BoardPinService}: a board can only be
 * pinned if the caller can READ it (IDOR guard). Session-auth only
 * (#[NoAdminRequired]); errors flow through {@see ApiErrorTrait}.
 */
class BoardPinController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BoardPinService $boardPinService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Pin a board (idempotent). READ-gated: the caller must be able to read the
	 * board. Returns the new pinned state.
	 */
	#[NoAdminRequired]
	public function pin(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->boardPinService->pin($this->currentUserId(), $id);
			return new JSONResponse(['pinned' => true]);
		});
	}

	/**
	 * Unpin a board (idempotent). Removing one's own pin needs no read-gate.
	 * Returns the new pinned state.
	 */
	#[NoAdminRequired]
	public function unpin(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->boardPinService->unpin($this->currentUserId(), $id);
			return new JSONResponse(['pinned' => false]);
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

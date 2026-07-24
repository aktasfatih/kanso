<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ReviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Card review-request endpoints. Requesting/withdrawing a review needs EDIT on
 * the board (like assigning); recording a verdict is limited to the reviewer
 * themselves (enforced in {@see ReviewService}). All are idempotent and return
 * an empty body — the client refetches the card/board over the realtime path.
 */
class ReviewController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ReviewService $reviewService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Requests a review of the card from $userId. Idempotent — a repeat request
	 * of an already-requested reviewer succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function request(int $id, string $userId): JSONResponse {
		return $this->respond(function () use ($id, $userId): JSONResponse {
			$this->reviewService->requestReview($id, $userId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Withdraws a review request. Idempotent — withdrawing an absent request
	 * succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function withdraw(int $id, string $userId): JSONResponse {
		return $this->respond(function () use ($id, $userId): JSONResponse {
			$this->reviewService->withdrawReview($id, $userId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Records the reviewer's verdict (approved / changes_requested) on their own
	 * review. Only the reviewer may call this; anyone else gets a 403.
	 */
	#[NoAdminRequired]
	public function setState(int $id, string $userId, string $state = ''): JSONResponse {
		return $this->respond(function () use ($id, $userId, $state): JSONResponse {
			$this->reviewService->setState($id, $userId, $state, $this->currentUserId());
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

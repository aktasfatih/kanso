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
 * an empty body - the client refetches the card/board over the realtime path.
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
	 * Requests a review of the card from $userId. Idempotent - a repeat request
	 * of an already-requested reviewer succeeds without writing anything.
	 */
	/**
	 * The current user's cross-board "My Reviews" feed - every review requested
	 * from them on a board they can read, newest first.
	 */
	#[NoAdminRequired]
	public function mine(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->reviewService->findMine($this->currentUserId()));
		});
	}

	#[NoAdminRequired]
	public function request(int $id, string $userId, ?int $reviewTypeId = null): JSONResponse {
		return $this->respond(function () use ($id, $userId, $reviewTypeId): JSONResponse {
			$this->reviewService->requestReview($id, $userId, $this->currentUserId(), $reviewTypeId);
			return new JSONResponse([]);
		});
	}

	/**
	 * Withdraws a review request. Idempotent - withdrawing an absent request
	 * succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function withdraw(int $id, int $reviewId): JSONResponse {
		return $this->respond(function () use ($id, $reviewId): JSONResponse {
			$this->reviewService->withdrawReview($id, $reviewId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Records the reviewer's verdict (approved / changes_requested) on their own
	 * review, targeted by review id. Only the reviewer may call this; anyone else
	 * gets a 403. A `reason` on a changes-requested verdict is posted as a card
	 * comment by the reviewer.
	 */
	#[NoAdminRequired]
	public function setState(int $id, int $reviewId, string $state = '', ?string $reason = null): JSONResponse {
		return $this->respond(function () use ($id, $reviewId, $state, $reason): JSONResponse {
			$this->reviewService->setState($id, $reviewId, $state, $this->currentUserId(), $reason);
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

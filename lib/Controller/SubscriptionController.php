<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Card watcher (subscription) endpoints. All return the card-level watch block
 * {subscribed, subscribers, count} for the current user.
 */
class SubscriptionController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private SubscriptionService $subscriptionService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->subscriptionService->getCardSubscription($cardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function subscribe(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->subscriptionService->subscribe($cardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function unsubscribe(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->subscriptionService->unsubscribe($cardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function subscribeOther(int $cardId, string $userId): JSONResponse {
		return $this->respond(function () use ($cardId, $userId): JSONResponse {
			return new JSONResponse(
				$this->subscriptionService->subscribeOther($cardId, $userId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function unsubscribeOther(int $cardId, string $userId): JSONResponse {
		return $this->respond(function () use ($cardId, $userId): JSONResponse {
			return new JSONResponse(
				$this->subscriptionService->unsubscribeOther($cardId, $userId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function boardIndex(int $boardId): JSONResponse {
		return $this->respond(function () use ($boardId): JSONResponse {
			return new JSONResponse(
				$this->subscriptionService->getBoardSubscription($boardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function boardSubscribe(int $boardId): JSONResponse {
		return $this->respond(function () use ($boardId): JSONResponse {
			return new JSONResponse(
				$this->subscriptionService->subscribeBoard($boardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function boardUnsubscribe(int $boardId): JSONResponse {
		return $this->respond(function () use ($boardId): JSONResponse {
			return new JSONResponse(
				$this->subscriptionService->unsubscribeBoard($boardId, $this->currentUserId())
			);
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

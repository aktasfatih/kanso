<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardTimerService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class CardTimerController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardTimerService $timerService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function start(int $cardId, ?string $actorName = null): JSONResponse {
		return $this->respond(fn (): JSONResponse => new JSONResponse(
			$this->timerService->start($cardId, $this->currentUserId(), $actorName)
		));
	}

	#[NoAdminRequired]
	public function stop(int $cardId): JSONResponse {
		return $this->respond(fn (): JSONResponse => new JSONResponse(
			$this->timerService->stop($cardId, $this->currentUserId())
		));
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotPermittedException('No authenticated user');
		}
		return $user->getUID();
	}
}

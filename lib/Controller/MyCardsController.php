<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\MyCardsService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The cross-board "My tasks" feed — open cards assigned to the current user
 * across every board they can read (ACL enforced in {@see MyCardsService}).
 */
class MyCardsController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private MyCardsService $myCardsService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->myCardsService->findMine($this->currentUserId()));
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

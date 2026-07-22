<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\StackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class StackController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private StackService $stackService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function create(int $boardId = 0, string $title = ''): JSONResponse {
		return $this->respond(function () use ($boardId, $title): JSONResponse {
			return new JSONResponse(
				$this->stackService->create($boardId, $title, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?bool $archived = null): JSONResponse {
		return $this->respond(function () use ($id, $title, $archived): JSONResponse {
			return new JSONResponse(
				$this->stackService->update($id, $title, $archived, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->stackService->delete($id, $this->currentUserId())
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

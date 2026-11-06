<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ProjectShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ProjectShareController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ProjectShareService $shareService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse($this->shareService->listForProject($id, $this->currentUserId()));
		});
	}

	#[NoAdminRequired]
	public function create(int $id, string $participant = '', string $participantType = '', int $permission = 0): JSONResponse {
		return $this->respond(function () use ($id, $participant, $participantType, $permission): JSONResponse {
			return new JSONResponse(
				$this->shareService->create($id, $participant, $participantType, $permission, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function update(int $id, int $shareId, ?int $permission = null): JSONResponse {
		return $this->respond(function () use ($id, $shareId, $permission): JSONResponse {
			if ($permission === null) {
				throw new InvalidInputException('Permission is required');
			}
			return new JSONResponse(
				$this->shareService->update($id, $shareId, $permission, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id, int $shareId): JSONResponse {
		return $this->respond(function () use ($id, $shareId): JSONResponse {
			$this->shareService->delete($id, $shareId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	#[NoAdminRequired]
	public function leave(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->shareService->leave($id, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	#[NoAdminRequired]
	public function search(int $id, string $q = ''): JSONResponse {
		return $this->respond(function () use ($id, $q): JSONResponse {
			return new JSONResponse($this->shareService->search($id, $q, $this->currentUserId()));
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

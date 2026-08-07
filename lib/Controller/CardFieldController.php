<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardFieldService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-board custom-field DEFINITION CRUD (#3537). Managing the definition list
 * needs MANAGE (gated in the service). Mirrors ReviewTypeController: the board
 * payload carries the definition list, so there is no index endpoint.
 */
class CardFieldController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardFieldService $cardFieldService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param string[]|null $options
	 */
	#[NoAdminRequired]
	public function create(int $boardId = 0, string $name = '', string $type = '', ?array $options = null): JSONResponse {
		return $this->respond(function () use ($boardId, $name, $type, $options): JSONResponse {
			return new JSONResponse(
				$this->cardFieldService->create($boardId, $name, $type, $options, $this->currentUserId())
			);
		});
	}

	/**
	 * @param string[]|null $options
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $name = null, ?array $options = null, ?string $sortKey = null): JSONResponse {
		return $this->respond(function () use ($id, $name, $options, $sortKey): JSONResponse {
			return new JSONResponse(
				$this->cardFieldService->update($id, $name, $options, $sortKey, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->cardFieldService->delete($id, $this->currentUserId());
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

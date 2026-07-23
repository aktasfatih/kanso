<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\ChecklistService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Card checklist (todo item) endpoints. Item mutations are card updates as far
 * as sync is concerned, so they flow through the same change log as the rest of
 * the card surface.
 */
class ChecklistController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ChecklistService $checklistService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->checklistService->listItems($cardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function create(int $cardId, string $title = ''): JSONResponse {
		return $this->respond(function () use ($cardId, $title): JSONResponse {
			return new JSONResponse(
				$this->checklistService->addItem($cardId, $title, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function update(int $itemId, ?string $title = null, ?bool $done = null): JSONResponse {
		return $this->respond(function () use ($itemId, $title, $done): JSONResponse {
			return new JSONResponse(
				$this->checklistService->updateItem($itemId, $title, $done, $this->currentUserId())
			);
		});
	}

	/**
	 * Moves the item directly after $afterItemId (null = top of the checklist).
	 * A sort-key overflow surfaces as 409 {"error": "rebalance_required"} via
	 * ApiErrorTrait.
	 */
	#[NoAdminRequired]
	public function move(int $itemId, ?int $afterItemId = null): JSONResponse {
		return $this->respond(function () use ($itemId, $afterItemId): JSONResponse {
			return new JSONResponse(
				$this->checklistService->moveItem($itemId, $afterItemId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $itemId): JSONResponse {
		return $this->respond(function () use ($itemId): JSONResponse {
			$this->checklistService->deleteItem($itemId, $this->currentUserId());
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

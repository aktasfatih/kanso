<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Single-card endpoints. All responses serialize the full card payload
 * (including the description) — only the board/stack listings use the
 * summary shape.
 */
class CardController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardService $cardService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function create(int $stackId = 0, string $title = ''): JSONResponse {
		return $this->respond(function () use ($stackId, $title): JSONResponse {
			return new JSONResponse(
				$this->cardService->create($stackId, $title, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->cardService->find($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function update(
		int $id,
		?string $title = null,
		?string $description = null,
		?string $duedate = null,
		?bool $done = null,
		?bool $archived = null,
	): JSONResponse {
		return $this->respond(function () use ($id, $title, $description, $duedate, $done, $archived): JSONResponse {
			return new JSONResponse(
				$this->cardService->update($id, $title, $description, $duedate, $done, $archived, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->cardService->delete($id, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Moves the card into $targetStackId, directly after $afterCardId
	 * (null = top of the stack). A sort-key overflow surfaces as
	 * 409 {"error": "rebalance_required"} via ApiErrorTrait.
	 */
	#[NoAdminRequired]
	public function move(int $id, int $targetStackId = 0, ?int $afterCardId = null): JSONResponse {
		return $this->respond(function () use ($id, $targetStackId, $afterCardId): JSONResponse {
			return new JSONResponse(
				$this->cardService->move($id, $targetStackId, $afterCardId, $this->currentUserId())
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

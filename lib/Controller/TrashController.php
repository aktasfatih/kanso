<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\TrashService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Trash endpoints: list soft-deleted cards of a board, restore one, or purge
 * one permanently. Trash summaries use the same shape as board card summaries.
 */
class TrashController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private TrashService $trashService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(array_map(
				static fn (Card $card): array => $card->jsonSerializeSummary(),
				$this->trashService->listTrash($id, $this->currentUserId())
			));
		});
	}

	#[NoAdminRequired]
	public function restore(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->trashService->restore($id, $this->currentUserId())->jsonSerializeSummary()
			);
		});
	}

	#[NoAdminRequired]
	public function purge(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->trashService->purge($id, $this->currentUserId());
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

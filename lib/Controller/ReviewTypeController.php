<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ReviewTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-board review-type CRUD endpoints (managing the type list needs MANAGE).
 * Mirrors LabelController. The board payload carries the type list, so there is
 * no index endpoint.
 */
class ReviewTypeController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ReviewTypeService $reviewTypeService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function create(int $boardId = 0, string $title = '', ?string $color = null): JSONResponse {
		return $this->respond(function () use ($boardId, $title, $color): JSONResponse {
			return new JSONResponse(
				$this->reviewTypeService->create($boardId, $title, $color, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?string $color = null): JSONResponse {
		return $this->respond(function () use ($id, $title, $color): JSONResponse {
			return new JSONResponse(
				$this->reviewTypeService->update($id, $title, $color, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->reviewTypeService->delete($id, $this->currentUserId());
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

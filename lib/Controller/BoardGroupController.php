<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\BoardGroupService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user board folders for the nav (#3529). Every endpoint is scoped to the
 * session user via {@see BoardGroupService}: folders are looked up by uid and a
 * board can only be filed into a folder if the caller can READ it. Session-auth
 * only (#[NoAdminRequired]); errors flow through {@see ApiErrorTrait}.
 */
class BoardGroupController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BoardGroupService $boardGroupService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The caller's folders (ordered), each with its readable board-id list.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->boardGroupService->listGroups($this->currentUserId()));
		});
	}

	#[NoAdminRequired]
	public function create(string $name = ''): JSONResponse {
		return $this->respond(function () use ($name): JSONResponse {
			return new JSONResponse(
				$this->boardGroupService->createGroup($this->currentUserId(), $name)
			);
		});
	}

	#[NoAdminRequired]
	public function rename(int $id, string $name = ''): JSONResponse {
		return $this->respond(function () use ($id, $name): JSONResponse {
			return new JSONResponse(
				$this->boardGroupService->renameGroup($this->currentUserId(), $id, $name)
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->boardGroupService->deleteGroup($this->currentUserId(), $id);
			return new JSONResponse([]);
		});
	}

	/**
	 * Reorder the caller's folders. Body: `groupIds` = the id order.
	 *
	 * @param int[] $groupIds
	 */
	#[NoAdminRequired]
	public function reorder(array $groupIds = []): JSONResponse {
		return $this->respond(function () use ($groupIds): JSONResponse {
			return new JSONResponse(
				$this->boardGroupService->reorderGroups($this->currentUserId(), $groupIds)
			);
		});
	}

	/**
	 * File a board into a folder (idempotent). The board must be READable by the
	 * caller and the folder must be theirs.
	 */
	#[NoAdminRequired]
	public function assign(int $id, int $boardId): JSONResponse {
		return $this->respond(function () use ($id, $boardId): JSONResponse {
			$this->boardGroupService->assignBoard($this->currentUserId(), $id, $boardId);
			return new JSONResponse([]);
		});
	}

	/**
	 * Remove a board from whatever folder the caller filed it in (idempotent).
	 */
	#[NoAdminRequired]
	public function unassign(int $boardId): JSONResponse {
		return $this->respond(function () use ($boardId): JSONResponse {
			$this->boardGroupService->unassignBoard($this->currentUserId(), $boardId);
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

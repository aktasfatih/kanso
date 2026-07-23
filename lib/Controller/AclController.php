<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\AclService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Board sharing endpoints. `{id}` is the board id — every ACL operation is
 * scoped to its board, and AclService rejects rules of other boards.
 */
class AclController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private AclService $aclService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function create(int $id, string $participant = '', string $participantType = '', int $permission = 0): JSONResponse {
		return $this->respond(function () use ($id, $participant, $participantType, $permission): JSONResponse {
			return new JSONResponse(
				$this->aclService->create($id, $participant, $participantType, $permission, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function update(int $id, int $aclId, int $permission = 0): JSONResponse {
		return $this->respond(function () use ($id, $aclId, $permission): JSONResponse {
			return new JSONResponse(
				$this->aclService->update($id, $aclId, $permission, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id, int $aclId): JSONResponse {
		return $this->respond(function () use ($id, $aclId): JSONResponse {
			$this->aclService->delete($id, $aclId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	#[NoAdminRequired]
	public function search(int $id, string $q = ''): JSONResponse {
		return $this->respond(function () use ($id, $q): JSONResponse {
			return new JSONResponse(
				$this->aclService->search($id, $q, $this->currentUserId())
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

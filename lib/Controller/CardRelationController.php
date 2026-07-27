<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardRelationService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Card-to-card relation endpoints: list a card's relations (grouped), add a
 * relation to another card on the same board, and remove one. Reading needs
 * READ, mutating needs EDIT (enforced in {@see CardRelationService}).
 */
class CardRelationController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardRelationService $relationService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $cardId): JSONResponse {
		return $this->respond(function () use ($cardId): JSONResponse {
			return new JSONResponse(
				$this->relationService->relationsForCard($cardId, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function create(int $cardId, int $otherCardId = 0, string $kind = ''): JSONResponse {
		return $this->respond(function () use ($cardId, $otherCardId, $kind): JSONResponse {
			return new JSONResponse(
				$this->relationService->addRelation($cardId, $otherCardId, $kind, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $cardId, int $relationId): JSONResponse {
		return $this->respond(function () use ($relationId): JSONResponse {
			$this->relationService->removeRelation($relationId, $this->currentUserId());
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

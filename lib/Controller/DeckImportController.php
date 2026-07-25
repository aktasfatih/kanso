<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\DeckImportService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * One-click Deck import: list the user's importable Deck boards and import one
 * into a fresh Kanso board.
 */
class DeckImportController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private DeckImportService $importService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			$uid = $this->currentUserId();
			return new JSONResponse([
				'available' => $this->importService->isDeckAvailable(),
				'boards' => $this->importService->listImportableBoards($uid),
			]);
		});
	}

	#[NoAdminRequired]
	public function import(int $deckBoardId): JSONResponse {
		return $this->respond(function () use ($deckBoardId): JSONResponse {
			return new JSONResponse(
				$this->importService->importBoard($deckBoardId, $this->currentUserId())
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

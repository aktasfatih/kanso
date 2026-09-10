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

	/**
	 * @param bool $confirm the caller has explicitly confirmed a re-import of a
	 *                      Deck board they already imported. Defaults to false, so a plain
	 *                      double-submit is answered with 409 `already_imported` instead of silently
	 *                      producing a second board.
	 */
	#[NoAdminRequired]
	public function import(int $deckBoardId, bool $confirm = false): JSONResponse {
		return $this->respond(function () use ($deckBoardId, $confirm): JSONResponse {
			return new JSONResponse(
				$this->importService->importBoard($deckBoardId, $this->currentUserId(), $confirm)
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

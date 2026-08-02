<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\TrelloImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Trello import: turn a pasted/uploaded Trello board JSON export into a fresh
 * Kanso board owned by the caller. Mirrors {@see BoardPortabilityController}'s
 * shape - the raw document text is passed straight through so the size cap and
 * shape validation in {@see TrelloImportService} stay meaningful.
 */
class TrelloImportController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private TrelloImportService $importService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Imports a Trello board export into a brand-new board owned by the caller.
	 * Any logged-in user may import their own upload.
	 */
	#[NoAdminRequired]
	public function import(string $document = ''): JSONResponse {
		return $this->respond(function () use ($document): JSONResponse {
			return new JSONResponse(
				$this->importService->import($document, $this->currentUserId())
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

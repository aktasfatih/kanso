<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\ExportService;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Full board data portability in Kanso's OWN round-trippable JSON format
 * (distinct from the Deck importer): export a whole board to a single document,
 * and import such a document back into a fresh board owned by the importer.
 */
class BoardPortabilityController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BoardService $boardService,
		private ExportService $exportService,
		private ImportService $importService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Exports one board as a Kanso export envelope. Gated on board READ (the
	 * board load itself asserts it).
	 */
	#[NoAdminRequired]
	public function export(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$uid = $this->currentUserId();
			$board = $this->boardService->find($id, $uid);
			return new JSONResponse($this->exportService->export($board));
		});
	}

	/**
	 * Imports a pasted/uploaded Kanso export document into a brand-new board
	 * owned by the caller. The raw document text is passed straight through so
	 * the size cap and version/shape validation stay meaningful.
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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
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
		private BoardAccess $boardAccess,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Exports one board as a Kanso export envelope. Gated on board READ (the
	 * board load itself asserts it) AND on the internal role (#3744): bulk
	 * egress of a whole board is denied to external (client-side) members -
	 * the industry norm for guest/client roles - so an external gets a 403.
	 */
	#[NoAdminRequired]
	public function export(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$uid = $this->currentUserId();
			$board = $this->boardService->find($id, $uid);
			// The export content is scoped to the VIEWER's visible card set
			// (#3743) - READ on the board alone must not dump hidden cards.
			$viewer = $this->boardAccess->contextFor($board, $uid);
			$this->assertInternal($viewer);
			return new JSONResponse($this->exportService->export($board, $viewer));
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
	 * Duplicates an existing board the caller can READ into a fresh board owned
	 * by the caller. The board load asserts READ; internal role required, same
	 * rationale as {@see self::export()} (#3744 - duplicate is export→import
	 * in-process, so it is the same whole-board egress). The new board's title
	 * is "<original> (copy)". `withCards` also clones the card graph; when
	 * false a structural-only clone (stacks/roles/labels/rules) is produced.
	 */
	#[NoAdminRequired]
	public function duplicate(int $id, bool $withCards = false): JSONResponse {
		return $this->respond(function () use ($id, $withCards): JSONResponse {
			$uid = $this->currentUserId();
			$board = $this->boardService->find($id, $uid);
			$this->assertInternal($this->boardAccess->contextFor($board, $uid));
			return new JSONResponse(
				$this->importService->duplicate($board, $uid, $withCards)
			);
		});
	}

	/**
	 * Whole-board egress (export / duplicate) is internal-only (#3744, the
	 * decided policy): an EXTERNAL member gets a plain 403. Not a 404 - the
	 * board itself is visible to them, so denial reveals nothing new.
	 *
	 * @throws NotPermittedException if the viewer's board side is external
	 */
	private function assertInternal(ViewerContext $viewer): void {
		if (!$viewer->isInternal()) {
			throw new NotPermittedException('Board export is limited to internal members');
		}
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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\DeckImportService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
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

	/**
	 * Lists the Deck boards the caller could import.
	 *
	 * Deliberately NOT rate limited, the same judgement the attachment listing
	 * makes ({@see \OCA\Kanso\Controller\CardAttachmentController::index()}): this
	 * is a read that costs a fixed handful of queries no matter how many boards
	 * come back - {@see \OCA\Kanso\Service\DeckReader::listImportableBoards()}
	 * resolves the readable ids, selects those boards, and counts their cards in
	 * ONE grouped query, so there is no per-board fan-out to amplify. That fixed
	 * cost, not the call count, is what decides it: the import screen re-reads the
	 * picker every time it is opened, so during a multi-board migration these run
	 * roughly one-to-one with the imports below.
	 */
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
	 * Imports ONE Deck board into a fresh Kanso board owned by the caller.
	 *
	 * Per-user rate limited, like the other endpoints that turn one request into a
	 * board's worth of rows: one call creates the board plus its stacks, cards,
	 * labels, assignments and comments, and copies attachment bytes into this
	 * app's own app-data (from Deck's app-data, or from the file owner's Files for
	 * the reference kind - an attachment whose source is missing, oversized or
	 * unreadable is skipped and counted, never fatal). The optional instance-wide
	 * attachment cap bounds those BYTES only where an admin opted in; this bounds
	 * the request COUNT everywhere.
	 *
	 * The ceiling is HIGHER than the document-upload importers'
	 * ({@see \OCA\Kanso\Controller\CsvImportController::import()} and
	 * {@see \OCA\Kanso\Controller\BoardPortabilityController::import()}, 60/hour)
	 * because the unit of work is not the same. Those take one hand-picked
	 * client-supplied document per call, so 60 of them in an hour already exceeds
	 * anything a human does. This one imports one BOARD per call, off a list the
	 * SERVER enumerates: {@see \OCA\Kanso\Service\DeckReader::listImportableBoards()}
	 * offers every board the caller owns AND every board with a direct ACL for
	 * them, which on a large team runs well past 60 before anyone retries a board
	 * that failed. A whole-footprint migration is therefore a long run of
	 * sequential requests, and stranding one half-finished on the primary adoption
	 * path is the worse failure - so this matches the per-file attachment writes
	 * ({@see \OCA\Kanso\Controller\CardAttachmentController::create()}), which are
	 * loose for the same "many small legitimate requests in one sitting" reason.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
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

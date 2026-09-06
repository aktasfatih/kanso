<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Service\BoardArchiveService;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\ImportService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Full board data portability in Kanso's OWN round-trippable format (distinct
 * from the Deck importer): export a whole board to a single archive, and import
 * such a document back into a fresh board owned by the importer.
 */
class BoardPortabilityController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BoardService $boardService,
		private BoardArchiveService $archiveService,
		private ImportService $importService,
		private BoardAccess $boardAccess,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Streams one board as a Kanso export archive: a zip holding `board.json`
	 * plus every attachment the caller may see. Gated on board READ (the board
	 * load itself asserts it) AND on the internal role (#3744): bulk egress of
	 * a whole board is denied to external (client-side) members - the industry
	 * norm for guest/client roles - so an external gets a 403.
	 *
	 * The archive is built as a temp FILE and streamed, never assembled in
	 * memory, so a board with large attachments cannot exhaust the worker.
	 */
	#[NoAdminRequired]
	public function export(int $id): Response {
		try {
			$uid = $this->currentUserId();
			$board = $this->boardService->find($id, $uid);
			// The export content is scoped to the VIEWER's visible card set
			// (#3743) - READ on the board alone must not dump hidden cards,
			// nor their files.
			$viewer = $this->boardAccess->contextFor($board, $uid);
			$this->assertInternal($viewer);

			$path = $this->archiveService->build($board, $viewer);
			// Open the handle, then drop the directory entry immediately: the
			// bytes stay readable through the descriptor, and the temp file
			// cannot outlive this request even if the client aborts mid-stream.
			$handle = @fopen($path, 'rb');
			@unlink($path);
			if ($handle === false) {
				throw new \RuntimeException('Could not open the export archive');
			}

			$response = new StreamResponse($handle);
			$response->addHeader('Content-Type', 'application/zip');
			$response->addHeader(
				'Content-Disposition',
				'attachment; filename="' . $this->archiveService->filenameFor($board) . '"'
			);
			$response->addHeader('X-Content-Type-Options', 'nosniff');
			return $response;
		} catch (\Throwable $e) {
			// Reuse the shared error mapping (403/404/400) for the failure
			// paths; on success the stream was already returned above.
			return $this->respond(function () use ($e): JSONResponse {
				throw $e;
			});
		}
	}

	/**
	 * Imports a Kanso export into a brand-new board owned by the caller, closing
	 * the round trip {@see self::export()} opens.
	 *
	 * Two request shapes, in that order of preference:
	 *  - multipart with a `file` part: the .zip archive this app exports, or any
	 *    older bare .json export. The uploaded FILE is handed to the service so
	 *    the archive can be streamed rather than buffered, and so which shape it
	 *    is gets decided from the bytes rather than from the client's filename;
	 *  - a JSON body with `document`: the raw export text, for scripted clients
	 *    (and every caller written before the format became an archive).
	 *
	 * Rate-limited per user, because this is the one endpoint where a SINGLE
	 * request can write many app-data objects at once - up to
	 * {@see \OCA\Kanso\Service\ImportArchiveReader::MAX_ENTRIES} of them, totalling
	 * {@see \OCA\Kanso\Service\ImportArchiveReader::MAX_TOTAL_BYTES}. That per-request
	 * ceiling is the important half; this limit bounds the amplification over
	 * time. It is set well above any believable human use (restoring a handful of
	 * board backups) so a legitimate restore session is never interrupted -
	 * including a CI run that imports repeatedly - while a scripted loop is
	 * stopped. Per-FILE upload
	 * ({@see \OCA\Kanso\Controller\CardAttachmentController::create()}) now carries
	 * its own, looser limit, so neither way into app-data is the unguarded one.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function import(string $document = ''): JSONResponse {
		return $this->respond(function () use ($document): JSONResponse {
			$uid = $this->currentUserId();
			$upload = $this->request->getUploadedFile('file');
			if (is_array($upload)) {
				return new JSONResponse($this->importService->importUploadedFile($upload, $uid));
			}
			return new JSONResponse($this->importService->import($document, $uid));
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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\StatsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only board analytics endpoint (GET /api/boards/{id}/stats).
 *
 * A thin controller: the only gate is BoardService::find($id, $uid), which
 * asserts PERMISSION_READ and throws NotPermittedException (→ 403) on denial.
 * Every StatsService aggregate is then board-scoped by board_id, so a reader
 * of one board can never see another's numbers.
 */
class BoardStatsController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BoardService $boardService,
		private StatsService $statsService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The board-stats DTO for a readable board. Asserts read access first
	 * (the ACL gate), then returns the composed aggregates.
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->boardService->find($id, $this->currentUserId());
			return new JSONResponse($this->statsService->boardStats($id));
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

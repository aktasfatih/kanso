<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Service\BoardService;
use OCA\Kanso\Service\BoardTimeStatsService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class BoardTimeStatsController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BoardService $boardService,
		private BoardAccess $boardAccess,
		private BoardTimeStatsService $timeStatsService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function show(int $id, ?string $from = null, ?string $to = null): JSONResponse {
		return $this->respond(function () use ($id, $from, $to): JSONResponse {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new NotPermittedException('No authenticated user');
			}
			$uid = $user->getUID();
			$board = $this->boardService->find($id, $uid);
			$viewer = $this->boardAccess->contextFor($board, $uid);
			return new JSONResponse($this->timeStatsService->get($board, $viewer, $from, $to));
		});
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\SearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * In-app search endpoint. Always ACL-filtered to the requester's readable
 * boards inside the service; no query language (single plain term).
 */
class SearchController extends Controller {
	use ApiErrorTrait;

	/** Hard cap so a client cannot request an unbounded page. */
	private const MAX_LIMIT = 50;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private SearchService $searchService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $q = '', ?int $boardId = null, int $limit = 25, int $offset = 0): JSONResponse {
		return $this->respond(function () use ($q, $boardId, $limit, $offset): JSONResponse {
			$limit = max(1, min($limit, self::MAX_LIMIT));
			$offset = max(0, $offset);
			return new JSONResponse(
				$this->searchService->search($q, $this->currentUserId(), $boardId, $limit, $offset)
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

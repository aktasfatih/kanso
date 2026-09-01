<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\MyCardsService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The cross-board "My tasks" feed - open cards assigned to the current user
 * across every board they can read (ACL enforced in {@see MyCardsService}).
 */
class MyCardsController extends Controller {
	use ApiErrorTrait;

	/**
	 * Signals that the feed hit {@see MyCardsService::LIMIT} and more assigned
	 * cards exist beyond it ('1') or that it is complete ('0').
	 */
	public const HEADER_TRUNCATED = 'X-Kanso-Truncated';

	/** The row cap the feed was built with. */
	public const HEADER_LIMIT = 'X-Kanso-Limit';

	/**
	 * How many days back the recently-done feed reaches, so the section can
	 * say what it covers instead of quietly hiding older completed work.
	 */
	public const HEADER_DONE_WINDOW_DAYS = 'X-Kanso-Done-Window-Days';

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private MyCardsService $myCardsService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			$feed = $this->myCardsService->findMine($this->currentUserId());
			// The body stays the plain card list this endpoint has always
			// returned (API clients keep working); the cap is reported in
			// headers so a client can say "showing the first 200" instead of
			// presenting a truncated window as the whole feed.
			$response = new JSONResponse($feed['cards']);
			$response->addHeader(self::HEADER_LIMIT, (string)$feed['limit']);
			$response->addHeader(self::HEADER_TRUNCATED, $feed['truncated'] ? '1' : '0');
			return $response;
		});
	}

	/**
	 * The opt-in "recently done" half of the feed (#10061).
	 *
	 * A SEPARATE endpoint on purpose: the default My Tasks load must issue no
	 * extra query, so nothing here runs until the user expands the section.
	 * Same body contract as index() - a plain card list, with the bounds
	 * reported in headers.
	 */
	#[NoAdminRequired]
	public function recentlyDone(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			$feed = $this->myCardsService->findMineRecentlyDone($this->currentUserId());
			$response = new JSONResponse($feed['cards']);
			$response->addHeader(self::HEADER_LIMIT, (string)$feed['limit']);
			$response->addHeader(self::HEADER_TRUNCATED, $feed['truncated'] ? '1' : '0');
			$response->addHeader(self::HEADER_DONE_WINDOW_DAYS, (string)$feed['windowDays']);
			return $response;
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

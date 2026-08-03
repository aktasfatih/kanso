<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\BulkCardService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Bulk (multi-select) card actions (#3523). ONE endpoint, ONE fixed action per
 * request applied to a list of card ids. The heavy lifting - the per-card loop,
 * per-card board ACL and the reuse of the existing single-card services - lives
 * in {@see BulkCardService}; this controller only unwraps the request and maps
 * whole-request failures to JSON via {@see ApiErrorTrait}.
 *
 * Per-card outcomes are NOT errors: the body always returns 200 with a
 * {"ok": [...], "skipped": [{id, reason}]} summary so a partially-permitted
 * selection reports exactly which cards were applied and which were skipped.
 */
class BulkCardController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private BulkCardService $bulkCardService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Applies $action (one of BulkCardService::ACTIONS) to every id in $cardIds,
	 * per card. $params carries the action's arguments (targetStackId / labelId /
	 * userId / duedate). An empty or oversized list, an unknown action or missing
	 * action params are a 400 for the whole request; individual cards the caller
	 * cannot edit are reported under "skipped", not as an error.
	 *
	 * @param int[] $cardIds
	 * @param array<string, mixed> $params
	 */
	#[NoAdminRequired]
	public function apply(array $cardIds = [], string $action = '', array $params = []): JSONResponse {
		return $this->respond(function () use ($cardIds, $action, $params): JSONResponse {
			return new JSONResponse(
				$this->bulkCardService->apply($cardIds, $action, $params, $this->currentUserId())
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

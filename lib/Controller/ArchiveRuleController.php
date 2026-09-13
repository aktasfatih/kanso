<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\ArchiveService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Auto-archive rule endpoints. Rules are board-automation config, so
 * mutations require MANAGE and listing requires READ (enforced in
 * {@see ArchiveService}). Errors map through ApiErrorTrait.
 */
class ArchiveRuleController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ArchiveService $archiveService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->archiveService->listForBoard($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function create(int $id, ?int $stackId = null, int $condition = 0, int $thresholdSeconds = 0): JSONResponse {
		return $this->respond(function () use ($id, $stackId, $condition, $thresholdSeconds): JSONResponse {
			return new JSONResponse(
				$this->archiveService->create($id, $stackId, $condition, $thresholdSeconds, $this->currentUserId())
			);
		});
	}

	/**
	 * Updates a rule. `stackId` is only touched when the client actually sent
	 * the key - that lets a rule be re-scoped to the whole board (stackId:
	 * null) without an omitted key silently clearing it.
	 */
	#[NoAdminRequired]
	public function update(
		int $id,
		?int $stackId = null,
		?int $condition = null,
		?int $thresholdSeconds = null,
		?bool $enabled = null,
	): JSONResponse {
		// Whether the client actually sent `stackId`. It has to come from the raw
		// request body: once the dispatcher has filled the argument above, an
		// omitted key and an explicit `null` are the same value, yet they mean
		// opposite things here - "leave the scope alone" vs "scope this rule to the
		// whole board". `array_key_exists` (not `isset`, and not
		// IRequest::getParam()'s sentinel-default trick, which is `isset`-based and
		// so reports an explicit null as absent - and can be spoofed by a client
		// sending the sentinel string itself) is what keeps a sent-but-null key
		// visible here.
		$stackIdProvided = array_key_exists('stackId', $this->request->getParams());
		return $this->respond(function () use ($id, $stackId, $stackIdProvided, $condition, $thresholdSeconds, $enabled): JSONResponse {
			return new JSONResponse(
				$this->archiveService->update(
					$id,
					$stackId,
					$stackIdProvided,
					$condition,
					$thresholdSeconds,
					$enabled,
					$this->currentUserId(),
				)
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->archiveService->delete($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function archiveNow(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse([
				'archived' => $this->archiveService->archiveNow($id, $this->currentUserId()),
			]);
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

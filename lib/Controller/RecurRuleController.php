<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\RecurrenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Recurring-card rule endpoints. Rules are board-automation config, so
 * mutations require MANAGE and listing requires READ (enforced in
 * {@see RecurrenceService}). create-now spawns one card immediately. Errors
 * map through ApiErrorTrait (bad RRULE / mode / policy → 400).
 */
class RecurRuleController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private RecurrenceService $recurrenceService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->recurrenceService->listForBoard($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function create(
		int $id,
		int $templateCardId = 0,
		int $targetStackId = 0,
		int $mode = 0,
		string $rrule = '',
		int $duedatePolicy = 0,
		int $duedateOffsetSeconds = 0,
		bool $skipWhileOpen = false,
	): JSONResponse {
		return $this->respond(function () use ($id, $templateCardId, $targetStackId, $mode, $rrule, $duedatePolicy, $duedateOffsetSeconds, $skipWhileOpen): JSONResponse {
			return new JSONResponse(
				$this->recurrenceService->create(
					$id,
					$templateCardId,
					$targetStackId,
					$mode,
					$rrule,
					$duedatePolicy,
					$duedateOffsetSeconds,
					$skipWhileOpen,
					$this->currentUserId(),
				)
			);
		});
	}

	#[NoAdminRequired]
	public function update(
		int $id,
		?int $templateCardId = null,
		?int $targetStackId = null,
		?int $mode = null,
		?string $rrule = null,
		?int $duedatePolicy = null,
		?int $duedateOffsetSeconds = null,
		?bool $skipWhileOpen = null,
		?bool $enabled = null,
	): JSONResponse {
		return $this->respond(function () use ($id, $templateCardId, $targetStackId, $mode, $rrule, $duedatePolicy, $duedateOffsetSeconds, $skipWhileOpen, $enabled): JSONResponse {
			return new JSONResponse(
				$this->recurrenceService->update(
					$id,
					$templateCardId,
					$targetStackId,
					$mode,
					$rrule,
					$duedatePolicy,
					$duedateOffsetSeconds,
					$skipWhileOpen,
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
				$this->recurrenceService->delete($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function createNow(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$card = $this->recurrenceService->createNow($id, $this->currentUserId());
			return new JSONResponse(['card' => $card]);
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

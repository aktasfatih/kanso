<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\AutomationService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-board automation-rule endpoints. All require MANAGE (enforced in
 * {@see AutomationService}) — automation is board configuration.
 */
class AutomationController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private AutomationService $automationService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse($this->automationService->listRules($id, $this->currentUserId()));
		});
	}

	/**
	 * @param array<string, mixed> $params
	 */
	#[NoAdminRequired]
	public function create(int $id, string $trigger = '', string $action = '', array $params = []): JSONResponse {
		return $this->respond(function () use ($id, $trigger, $action, $params): JSONResponse {
			return new JSONResponse(
				$this->automationService->createRule($id, $trigger, $action, $params, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function setEnabled(int $ruleId, bool $enabled = true): JSONResponse {
		return $this->respond(function () use ($ruleId, $enabled): JSONResponse {
			return new JSONResponse(
				$this->automationService->setEnabled($ruleId, $enabled, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $ruleId): JSONResponse {
		return $this->respond(function () use ($ruleId): JSONResponse {
			$this->automationService->deleteRule($ruleId, $this->currentUserId());
			return new JSONResponse([]);
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

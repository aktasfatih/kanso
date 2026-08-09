<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\GithubWebhookService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GitHub webhook: one PUBLIC ingest endpoint (HMAC-verified, no session) plus
 * MANAGE-only config endpoints to generate/rotate/disable the per-board secret.
 */
class WebhookController extends Controller {
	use ApiErrorTrait;

	/**
	 * The exact request body bytes, captured in the constructor BEFORE NC's
	 * argument resolution reads (and thus consumes) php://input - HMAC must run
	 * over these exact bytes, not a re-encoding of the parsed params.
	 */
	private string $rawBody;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private GithubWebhookService $webhookService,
	) {
		parent::__construct($appName, $request);
		$this->rawBody = file_get_contents('php://input') ?: '';
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'kansoWebhook')]
	public function github(int $id): JSONResponse {
		try {
			$signature = $this->request->getHeader('X-Hub-Signature-256');
			$result = $this->webhookService->handleWebhook($id, $signature, $this->rawBody);
			return new JSONResponse($result);
		} catch (NotPermittedException $e) {
			// Missing/invalid signature or a disabled webhook - a throttled failure
			// so repeated bad-signature deliveries to this public endpoint are
			// rate-limited (valid deliveries above are never throttled).
			$response = new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'kansoWebhook']);
			return $response;
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function config(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->webhookService->getConfig($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function rotate(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->webhookService->rotateSecret($id, $this->currentUserId())
			);
		});
	}

	/**
	 * Issue-intake config (#3752): the stack an opened issue's card lands in
	 * (null = intake off) plus an optional GitHub label filter. MANAGE-gated in
	 * the service.
	 */
	#[NoAdminRequired]
	public function intake(int $id, ?int $stackId = null, string $label = ''): JSONResponse {
		return $this->respond(function () use ($id, $stackId, $label): JSONResponse {
			return new JSONResponse(
				$this->webhookService->setIntakeConfig($id, $stackId, $label, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function disable(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->webhookService->disable($id, $this->currentUserId());
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

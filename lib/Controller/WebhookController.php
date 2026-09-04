<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\ForgejoWebhookService;
use OCA\Kanso\Service\GithubWebhookService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NonJsonWebhookBodyException;
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
 * Forge webhooks: one PUBLIC ingest endpoint per provider (HMAC-verified, no
 * session) plus MANAGE-only config endpoints to generate/rotate/disable the
 * per-board secret. GitHub and Forgejo are independent - a board may run either,
 * both, or neither.
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
		private ForgejoWebhookService $forgejoService,
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
		} catch (NonJsonWebhookBodyException $e) {
			// A correctly-signed delivery whose body is not JSON: the webhook was
			// created with the form-encoded content type. That is a misconfigured
			// user, not an attacker, so this is deliberately NOT throttled - and the
			// body carries the hint rather than a silent 200, so the mistake is
			// visible in GitHub's delivery log. No board data is exposed (#3760).
			//
			// The dedicated marker type matters: handleWebhook() also creates and
			// moves cards, and those throw the plain InvalidInputException below. On
			// the broad catch this hint went out for those too, telling a user whose
			// content type was already correct to go and change it.
			return new JSONResponse([
				'error' => 'invalid_payload',
				'hint' => 'Set the webhook Content type to application/json',
			], Http::STATUS_BAD_REQUEST);
		} catch (InvalidInputException $e) {
			// The body parsed fine, but the card create/move the event asked for was
			// rejected (e.g. an issue whose title trims to empty). Still a 400, still
			// not throttled, but reported as what it is - the message is the same one
			// the REST API returns for the identical rejection.
			return new JSONResponse([
				'error' => 'invalid_payload',
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Forgejo/Gitea ingest. Forgejo signs with a RAW hex digest in
	 * `X-Forgejo-Signature` / `X-Gitea-Signature` and also emits the
	 * GitHub-compatible header - the service accepts all three. A hook saved
	 * without a secret still sends those headers EMPTY, which must read as a
	 * rejection, not as "no signature offered".
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'kansoWebhook')]
	public function forgejo(int $id): JSONResponse {
		$signature = $this->request->getHeader('X-Forgejo-Signature');
		if ($signature === '') {
			$signature = $this->request->getHeader('X-Gitea-Signature');
		}
		if ($signature === '') {
			$signature = $this->request->getHeader('X-Hub-Signature-256');
		}

		try {
			$result = $this->forgejoService->handleWebhook($id, $signature, $this->rawBody);
			return new JSONResponse($result);
		} catch (NotPermittedException $e) {
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

	// ---- Forgejo config (MANAGE, gated in the service) ---------------------

	#[NoAdminRequired]
	public function forgejoConfig(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->forgejoService->getConfig($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function forgejoRotate(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse(
				$this->forgejoService->rotateSecret($id, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function forgejoIntake(int $id, ?int $stackId = null, string $label = ''): JSONResponse {
		return $this->respond(function () use ($id, $stackId, $label): JSONResponse {
			return new JSONResponse(
				$this->forgejoService->setIntakeConfig($id, $stackId, $label, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function forgejoDisable(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->forgejoService->disable($id, $this->currentUserId());
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

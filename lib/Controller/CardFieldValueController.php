<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardFieldValueService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-card custom-field VALUE writes (#3537). Setting/clearing a value is a
 * plain card edit, EDIT-gated in the service. A set is an upsert (one value per
 * card/field); the value rides the card DETAIL payload, not the summary.
 */
class CardFieldValueController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private CardFieldValueService $cardFieldValueService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function set(int $cardId, int $fieldId, ?string $value = null): JSONResponse {
		return $this->respond(function () use ($cardId, $fieldId, $value): JSONResponse {
			return new JSONResponse(
				$this->cardFieldValueService->set($cardId, $fieldId, $value, $this->currentUserId())
			);
		});
	}

	#[NoAdminRequired]
	public function clear(int $cardId, int $fieldId): JSONResponse {
		return $this->respond(function () use ($cardId, $fieldId): JSONResponse {
			$this->cardFieldValueService->clear($cardId, $fieldId, $this->currentUserId());
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

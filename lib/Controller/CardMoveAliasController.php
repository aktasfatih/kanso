<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CardMoveAliasService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class CardMoveAliasController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $session,
		private CardMoveAliasService $aliases,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function resolve(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$uid = $this->session->getUser()?->getUID();
			if ($uid === null) {
				throw new DoesNotExistException('Card does not exist');
			}
			$card = $this->aliases->resolve($id, $uid);
			return new JSONResponse(['cardId' => $card->getId(), 'boardId' => $card->getBoardId()]);
		});
	}
}

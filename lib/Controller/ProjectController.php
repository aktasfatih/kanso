<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ProjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Project endpoints - owner-only, cross-board card collections (#3447). Every
 * method acts as the current user; ownership and board-read ACL are enforced in
 * {@see ProjectService}. Membership mutations are idempotent and return an empty
 * body.
 */
class ProjectController extends Controller {
	use ApiErrorTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ProjectService $projectService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The current user's projects, in title order.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->projectService->findMine($this->currentUserId()));
		});
	}

	#[NoAdminRequired]
	public function create(string $title = '', ?string $description = null, ?string $color = null): JSONResponse {
		return $this->respond(function () use ($title, $description, $color): JSONResponse {
			return new JSONResponse(
				$this->projectService->create($this->currentUserId(), $title, $description, $color)->jsonSerialize()
			);
		});
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?string $description = null, ?string $color = null): JSONResponse {
		return $this->respond(function () use ($id, $title, $description, $color): JSONResponse {
			return new JSONResponse(
				$this->projectService->update($id, $this->currentUserId(), $title, $description, $color)->jsonSerialize()
			);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$this->projectService->delete($id, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * The project's cards, ACL-filtered to the owner's readable boards.
	 */
	#[NoAdminRequired]
	public function cards(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse($this->projectService->listCards($id, $this->currentUserId()));
		});
	}

	/**
	 * Cross-board analytics for the project, owner-gated and ACL-filtered to the
	 * owner's readable boards (mirrors board analytics). The owner gate + the
	 * one-pass ACL card resolution live in {@see ProjectService::stats()}, so a
	 * non-owner gets a 403 and a card on an unreadable board never contributes.
	 */
	#[NoAdminRequired]
	public function stats(int $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			return new JSONResponse($this->projectService->stats($id, $this->currentUserId()));
		});
	}

	/**
	 * Adds a card to the project. Idempotent - a repeat add succeeds without
	 * writing anything.
	 */
	#[NoAdminRequired]
	public function addCard(int $id, int $cardId): JSONResponse {
		return $this->respond(function () use ($id, $cardId): JSONResponse {
			$this->projectService->addCard($id, $cardId, $this->currentUserId());
			return new JSONResponse([]);
		});
	}

	/**
	 * Removes a card from the project. Idempotent - removing an absent membership
	 * succeeds without writing anything.
	 */
	#[NoAdminRequired]
	public function removeCard(int $id, int $cardId): JSONResponse {
		return $this->respond(function () use ($id, $cardId): JSONResponse {
			$this->projectService->removeCard($id, $cardId, $this->currentUserId());
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

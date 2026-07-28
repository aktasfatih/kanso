<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user Kanso preferences, stored in the NC user config (app 'kanso').
 * Currently just the "default board on start" (which board the app opens to);
 * an empty value means the board list.
 */
class SettingsController extends Controller {
	use ApiErrorTrait;

	private const KEY_DEFAULT_BOARD = 'default_board';

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The current user's Kanso preferences.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			$raw = $this->config->getUserValue($this->currentUserId(), 'kanso', self::KEY_DEFAULT_BOARD, '');
			return new JSONResponse([
				'defaultBoardId' => $raw === '' ? null : (int)$raw,
			]);
		});
	}

	/**
	 * Sets the default-board-on-start preference. A null/0 value clears it (the
	 * app opens to the board list). Board existence is NOT validated here - the
	 * client falls back to the board list if the stored board is gone.
	 */
	#[NoAdminRequired]
	public function update(?int $defaultBoardId = null): JSONResponse {
		return $this->respond(function () use ($defaultBoardId): JSONResponse {
			$value = ($defaultBoardId === null || $defaultBoardId <= 0) ? '' : (string)$defaultBoardId;
			$this->config->setUserValue($this->currentUserId(), 'kanso', self::KEY_DEFAULT_BOARD, $value);
			return new JSONResponse([
				'defaultBoardId' => $value === '' ? null : (int)$value,
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

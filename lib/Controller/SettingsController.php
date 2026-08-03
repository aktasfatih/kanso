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
	// Collapsed board-folder ids (#3529): a JSON list of the folder ids the user
	// has collapsed in the nav / boards page. A pure per-user view preference.
	private const KEY_COLLAPSED_GROUPS = 'collapsed_board_groups';
	// Whether the "Boards" section in the left nav is expanded (shows all boards)
	// or collapsed (hidden). A pure per-user view preference; defaults to open.
	private const KEY_BOARDS_NAV_OPEN = 'boards_nav_open';
	// Bound the value so a scripted client can't bloat the user-config row.
	private const MAX_COLLAPSED = 200;

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
			$uid = $this->currentUserId();
			$raw = $this->config->getUserValue($uid, 'kanso', self::KEY_DEFAULT_BOARD, '');
			return new JSONResponse([
				'defaultBoardId' => $raw === '' ? null : (int)$raw,
				'collapsedBoardGroups' => $this->readCollapsedGroups($uid),
				'boardsNavOpen' => $this->readBoardsNavOpen($uid),
			]);
		});
	}

	/**
	 * Sets the default-board-on-start preference. A null/0 value clears it (the
	 * app opens to the board list). Board existence is NOT validated here - the
	 * client falls back to the board list if the stored board is gone.
	 *
	 * `collapsedBoardGroups`, when provided, replaces the set of nav folders the
	 * user has collapsed (#3529); omitting it leaves that preference untouched.
	 *
	 * `boardsNavOpen`, when provided, sets whether the left-nav Boards section is
	 * expanded; omitting it leaves that preference untouched.
	 *
	 * @param ?int[] $collapsedBoardGroups
	 */
	#[NoAdminRequired]
	public function update(?int $defaultBoardId = null, ?array $collapsedBoardGroups = null, ?bool $boardsNavOpen = null): JSONResponse {
		return $this->respond(function () use ($defaultBoardId, $collapsedBoardGroups, $boardsNavOpen): JSONResponse {
			$uid = $this->currentUserId();
			$value = ($defaultBoardId === null || $defaultBoardId <= 0) ? '' : (string)$defaultBoardId;
			$this->config->setUserValue($uid, 'kanso', self::KEY_DEFAULT_BOARD, $value);

			if ($collapsedBoardGroups !== null) {
				$this->writeCollapsedGroups($uid, $collapsedBoardGroups);
			}

			if ($boardsNavOpen !== null) {
				$this->config->setUserValue($uid, 'kanso', self::KEY_BOARDS_NAV_OPEN, $boardsNavOpen ? '1' : '0');
			}

			return new JSONResponse([
				'defaultBoardId' => $value === '' ? null : (int)$value,
				'collapsedBoardGroups' => $this->readCollapsedGroups($uid),
				'boardsNavOpen' => $this->readBoardsNavOpen($uid),
			]);
		});
	}

	/**
	 * Whether the left-nav Boards section is expanded. Defaults to true (open)
	 * when the user has never set it, preserving the original always-open nav.
	 */
	private function readBoardsNavOpen(string $uid): bool {
		return $this->config->getUserValue($uid, 'kanso', self::KEY_BOARDS_NAV_OPEN, '1') !== '0';
	}

	/**
	 * The user's collapsed folder ids, tolerating a corrupt/legacy value.
	 *
	 * @return int[]
	 */
	private function readCollapsedGroups(string $uid): array {
		$raw = $this->config->getUserValue($uid, 'kanso', self::KEY_COLLAPSED_GROUPS, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_unique(array_map('intval', $decoded)));
	}

	/**
	 * @param int[] $ids
	 */
	private function writeCollapsedGroups(string $uid, array $ids): void {
		$clean = array_values(array_unique(array_map('intval', $ids)));
		if (count($clean) > self::MAX_COLLAPSED) {
			$clean = array_slice($clean, 0, self::MAX_COLLAPSED);
		}
		$this->config->setUserValue($uid, 'kanso', self::KEY_COLLAPSED_GROUPS, json_encode($clean) ?: '[]');
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

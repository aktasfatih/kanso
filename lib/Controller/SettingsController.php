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
	// Dismissed one-time onboarding hints (#3413): a JSON list of hint ids the
	// user has dismissed (e.g. the "press ? for shortcuts" nudge). Server-side so
	// a hint that is dismissed on one device stays dismissed everywhere.
	private const KEY_DISMISSED_HINTS = 'dismissed_hints';
	// Bound the value so a scripted client can't bloat the user-config row.
	private const MAX_COLLAPSED = 200;
	// Hint ids are shape-restricted (short slug) and capped, so the row can't be
	// abused as arbitrary per-user storage. This is a shape guard, not an
	// enumerated id allow-list — the frontend owns the concrete hint ids.
	private const MAX_HINTS = 50;

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
				'dismissedHints' => $this->readDismissedHints($uid),
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
	 * `dismissedHints`, when provided, replaces the set of dismissed one-time
	 * onboarding hints (#3413); omitting it leaves that preference untouched.
	 *
	 * @param ?int[] $collapsedBoardGroups
	 * @param ?string[] $dismissedHints
	 */
	#[NoAdminRequired]
	public function update(?int $defaultBoardId = null, ?array $collapsedBoardGroups = null, ?array $dismissedHints = null): JSONResponse {
		return $this->respond(function () use ($defaultBoardId, $collapsedBoardGroups, $dismissedHints): JSONResponse {
			$uid = $this->currentUserId();
			$value = ($defaultBoardId === null || $defaultBoardId <= 0) ? '' : (string)$defaultBoardId;
			$this->config->setUserValue($uid, 'kanso', self::KEY_DEFAULT_BOARD, $value);

			if ($collapsedBoardGroups !== null) {
				$this->writeCollapsedGroups($uid, $collapsedBoardGroups);
			}

			if ($dismissedHints !== null) {
				$this->writeDismissedHints($uid, $dismissedHints);
			}

			return new JSONResponse([
				'defaultBoardId' => $value === '' ? null : (int)$value,
				'collapsedBoardGroups' => $this->readCollapsedGroups($uid),
				'dismissedHints' => $this->readDismissedHints($uid),
			]);
		});
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
	 * The user's dismissed one-time hint ids, tolerating a corrupt/legacy value.
	 *
	 * @return string[]
	 */
	private function readDismissedHints(string $uid): array {
		$raw = $this->config->getUserValue($uid, 'kanso', self::KEY_DISMISSED_HINTS, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return $this->cleanHintIds($decoded);
	}

	/**
	 * @param string[] $ids
	 */
	private function writeDismissedHints(string $uid, array $ids): void {
		$clean = $this->cleanHintIds($ids);
		$this->config->setUserValue($uid, 'kanso', self::KEY_DISMISSED_HINTS, json_encode($clean) ?: '[]');
	}

	/**
	 * Normalise a list of hint ids: strings only, trimmed, de-duped, short slug
	 * shape enforced, and capped so the value can't be abused as free storage.
	 *
	 * @param array<mixed> $ids
	 * @return string[]
	 */
	private function cleanHintIds(array $ids): array {
		$clean = [];
		foreach ($ids as $id) {
			if (!is_string($id)) {
				continue;
			}
			$id = trim($id);
			// Accept only short slug-shaped ids (a-z, 0-9, '-', '_').
			if ($id === '' || strlen($id) > 64 || preg_match('/^[a-z0-9_-]+$/', $id) !== 1) {
				continue;
			}
			$clean[$id] = true;
		}
		$out = array_keys($clean);
		if (count($out) > self::MAX_HINTS) {
			$out = array_slice($out, 0, self::MAX_HINTS);
		}
		return $out;
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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OCP\IConfig;

/**
 * Per-user Kanso preferences, stored in the NC user config (app 'kanso').
 *
 * The storage layer behind GET/PUT /api/settings, factored out of
 * {@see \OCA\Kanso\Controller\SettingsController} so the page controllers can
 * embed the same payload in the app shell as initial state (#10460). Without
 * that, every page load rendered the *default* layout and then snapped to the
 * user's actual choice once the settings fetch resolved.
 *
 * Every read is a plain `IConfig::getUserValue` — one preferences load per
 * request, no `kanso_*` table involved — so embedding it in the page costs
 * nothing and adds no HTTP round-trip.
 *
 * Each preference is written on its own: the controller only calls the setter
 * for a key the request body actually carried. That rule is the endpoint's
 * contract (a control in the settings dialog saves only its own key), which is
 * why there is no "write everything" method here.
 */
class UserSettingsService {
	private const KEY_DEFAULT_BOARD = 'default_board';
	// Collapsed board-folder ids (#3529): a JSON list of the folder ids the user
	// has collapsed in the nav / boards page. A pure per-user view preference.
	private const KEY_COLLAPSED_GROUPS = 'collapsed_board_groups';
	// Dismissed one-time onboarding hints (#3413): a JSON list of hint ids the
	// user has dismissed (e.g. the "press ? for shortcuts" nudge). Server-side so
	// a hint that is dismissed on one device stays dismissed everywhere.
	private const KEY_DISMISSED_HINTS = 'dismissed_hints';
	// Hidden left-nav sections (#69): a JSON list of the top-level nav section
	// keys the user has chosen to hide. A pure per-user view preference.
	private const KEY_HIDDEN_NAV = 'hidden_nav_sections';
	// Editor formatting toolbar visibility: '1' = hidden, '0' or '' = shown.
	// A pure per-user view preference stored as a boolean-string.
	private const KEY_EDITOR_TOOLBAR = 'editor_toolbar_hidden';
	// Where the card view puts the Discussion/Activity panel (#10408): beside the
	// card ('side', today's behaviour and the default) or as a continuation of the
	// card that the user scrolls down to ('bottom'). Server-side rather than
	// localStorage on purpose - the choice follows the user across devices.
	//
	// Deliberately NOT routed through ChangeNotifier / kanso_changes: that log is
	// the per-board delta-sync feed, and a per-user view preference belongs to no
	// board. Appending to it would fabricate a board change for every other member
	// of every board the user can see.
	private const KEY_DISCUSSION_POSITION = 'card_discussion_position';
	// Fixed allow-list for the discussion position. Like ALLOWED_NAV below, this
	// is the security guard: anything off-list falls back to the default, so the
	// value can't be abused as arbitrary per-user storage.
	private const ALLOWED_DISCUSSION_POSITIONS = ['side', 'bottom'];
	// Today's layout, so an upgrade changes nobody's card view.
	private const DEFAULT_DISCUSSION_POSITION = 'side';
	// Fixed allow-list of toggleable nav sections. `boards` is intentionally
	// absent — Boards is always shown (hiding it would strand navigation). This
	// allow-list is the security guard: the value can't be abused as arbitrary
	// per-user storage because anything off-list is dropped.
	private const ALLOWED_NAV = ['my-tasks', 'my-reviews', 'inbox', 'views', 'projects'];
	// Bound the value so a scripted client can't bloat the user-config row.
	private const MAX_COLLAPSED = 200;
	// Hint ids are shape-restricted (short slug) and capped, so the row can't be
	// abused as arbitrary per-user storage. This is a shape guard, not an
	// enumerated id allow-list — the frontend owns the concrete hint ids.
	private const MAX_HINTS = 50;

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * Every preference as the API reports it. Shared by the read path, the write
	 * path and the page controllers' initial state, so a save, a fetch and the
	 * server-rendered seed can never disagree on the shape.
	 *
	 * @return array{defaultBoardId: ?int, collapsedBoardGroups: int[], dismissedHints: string[], hiddenNavSections: string[], editorToolbarHidden: bool, cardDiscussionPosition: string}
	 */
	public function readAll(string $uid): array {
		$raw = $this->config->getUserValue($uid, 'kanso', self::KEY_DEFAULT_BOARD, '');
		return [
			'defaultBoardId' => $raw === '' ? null : (int)$raw,
			'collapsedBoardGroups' => $this->readCollapsedGroups($uid),
			'dismissedHints' => $this->readDismissedHints($uid),
			'hiddenNavSections' => $this->readHiddenNav($uid),
			'editorToolbarHidden' => $this->readEditorToolbarHidden($uid),
			'cardDiscussionPosition' => $this->readDiscussionPosition($uid),
		];
	}

	/**
	 * Which board the app opens to; `null`/0 clears it (the app opens to the
	 * board list). Board existence is NOT validated here - the client falls back
	 * to the board list if the stored board is gone.
	 */
	public function setDefaultBoard(string $uid, ?int $boardId): void {
		$value = ($boardId === null || $boardId <= 0) ? '' : (string)$boardId;
		$this->config->setUserValue($uid, 'kanso', self::KEY_DEFAULT_BOARD, $value);
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
	public function setCollapsedGroups(string $uid, array $ids): void {
		$clean = array_values(array_unique(array_map('intval', $ids)));
		if (count($clean) > self::MAX_COLLAPSED) {
			$clean = array_slice($clean, 0, self::MAX_COLLAPSED);
		}
		$this->config->setUserValue($uid, 'kanso', self::KEY_COLLAPSED_GROUPS, json_encode($clean) ?: '[]');
	}

	/**
	 * The user's hidden left-nav sections, tolerating a corrupt/legacy value.
	 * Anything outside the allow-list is dropped (the security guard), so the
	 * value can only ever hold known section keys.
	 *
	 * @return string[]
	 */
	private function readHiddenNav(string $uid): array {
		$raw = $this->config->getUserValue($uid, 'kanso', self::KEY_HIDDEN_NAV, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return $this->cleanNavKeys($decoded);
	}

	/**
	 * @param string[] $keys
	 */
	public function setHiddenNav(string $uid, array $keys): void {
		$clean = $this->cleanNavKeys($keys);
		$this->config->setUserValue($uid, 'kanso', self::KEY_HIDDEN_NAV, json_encode($clean) ?: '[]');
	}

	/**
	 * Keep only known nav-section keys (allow-list filter), de-duped. Since the
	 * set is bounded to the allow-list no separate size cap is needed.
	 *
	 * @param array<mixed> $keys
	 * @return string[]
	 */
	private function cleanNavKeys(array $keys): array {
		$clean = [];
		foreach ($keys as $key) {
			if (is_string($key) && in_array($key, self::ALLOWED_NAV, true)) {
				$clean[$key] = true;
			}
		}
		return array_keys($clean);
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
	public function setDismissedHints(string $uid, array $ids): void {
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
	 * Whether the user has hidden the editor formatting toolbar.
	 * Stored as '1' (hidden) or '' (shown); defaults to false (shown).
	 */
	private function readEditorToolbarHidden(string $uid): bool {
		return $this->config->getUserValue($uid, 'kanso', self::KEY_EDITOR_TOOLBAR, '') === '1';
	}

	public function setEditorToolbarHidden(string $uid, bool $hidden): void {
		$this->config->setUserValue($uid, 'kanso', self::KEY_EDITOR_TOOLBAR, $hidden ? '1' : '0');
	}

	/**
	 * Where the card view puts the Discussion/Activity panel. Anything unset,
	 * corrupt or off the allow-list reads back as the default ('side'), so a bad
	 * stored value degrades to today's layout rather than to a broken card view.
	 */
	private function readDiscussionPosition(string $uid): string {
		$raw = $this->config->getUserValue($uid, 'kanso', self::KEY_DISCUSSION_POSITION, '');
		return in_array($raw, self::ALLOWED_DISCUSSION_POSITIONS, true)
			? $raw
			: self::DEFAULT_DISCUSSION_POSITION;
	}

	/** `null` (an explicit clear) and any off-list value both reset it to 'side'. */
	public function setDiscussionPosition(string $uid, ?string $position): void {
		$clean = in_array($position, self::ALLOWED_DISCUSSION_POSITIONS, true)
			? $position
			: self::DEFAULT_DISCUSSION_POSITION;
		$this->config->setUserValue($uid, 'kanso', self::KEY_DISCUSSION_POSITION, $clean);
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user, per-board saved filter views (#3407).
 *
 * A "saved filter" is a named snapshot of the board's client-side filter bar
 * (labels / assignees / due / done / priority). They are a pure personal
 * preference — no board data, no ACL implications — so, like the default-board
 * preference in {@see SettingsController}, they live in the NC user config
 * (app 'kanso') rather than in a dedicated table. This keeps the MVP lean: no
 * migration, no new mapper.
 *
 * Storage shape: one user-config key per user, `saved_filters`, holding a JSON
 * object keyed by board id → array of named views:
 *   { "<boardId>": [ { "name": "…", "filter": { … opaque client filter … } } ] }
 *
 * The `filter` blob is treated as opaque by the server: the client owns its
 * schema (dimensions come and go), the server only enforces size / count caps
 * and that names are unique per board. Board membership is NOT validated here —
 * a saved view referencing a board the user can no longer read simply never
 * gets requested (the client asks per board id).
 */
class SavedFilterController extends Controller {
	use ApiErrorTrait;

	private const KEY = 'saved_filters';

	/** Hard caps to keep the user-config value bounded. */
	private const MAX_PER_BOARD = 50;
	private const MAX_NAME_LEN = 100;
	private const MAX_FILTER_BYTES = 4096;

	// Global cap on the ENTIRE per-user value. board membership isn't validated
	// (saved filters are a personal preference), so a scripted client could seed
	// unlimited board keys; this bounds the whole row regardless of board count.
	private const MAX_TOTAL_BYTES = 262144; // 256 KB

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the current user's saved filters for one board, in insertion order.
	 */
	#[NoAdminRequired]
	public function index(int $boardId): JSONResponse {
		return $this->respond(function () use ($boardId): JSONResponse {
			$all = $this->readAll();
			return new JSONResponse(['filters' => $all[(string)$boardId] ?? []]);
		});
	}

	/**
	 * Create or overwrite a saved filter by name (upsert; names are unique per
	 * board). `filter` is the opaque client filter object. Returns the updated
	 * list for the board.
	 */
	#[NoAdminRequired]
	public function create(int $boardId, string $name = '', mixed $filter = null): JSONResponse {
		return $this->respond(function () use ($boardId, $name, $filter): JSONResponse {
			$name = trim($name);
			if ($name === '') {
				throw new InvalidInputException('A name is required.');
			}
			if (mb_strlen($name) > self::MAX_NAME_LEN) {
				$name = mb_substr($name, 0, self::MAX_NAME_LEN);
			}
			if (!is_array($filter)) {
				throw new InvalidInputException('A filter object is required.');
			}
			// Bound the opaque blob so a malicious/huge payload can't bloat the
			// user config. json_encode of the sanitised filter, size-checked.
			$encoded = json_encode($filter);
			if ($encoded === false || strlen($encoded) > self::MAX_FILTER_BYTES) {
				throw new InvalidInputException('Filter is too large.');
			}

			$all = $this->readAll();
			$key = (string)$boardId;
			$list = $all[$key] ?? [];

			// Upsert by (case-sensitive) name.
			$found = false;
			foreach ($list as &$view) {
				if (($view['name'] ?? '') === $name) {
					$view['filter'] = $filter;
					$found = true;
					break;
				}
			}
			unset($view);
			if (!$found) {
				if (count($list) >= self::MAX_PER_BOARD) {
					throw new InvalidInputException('Too many saved filters for this board.');
				}
				$list[] = ['name' => $name, 'filter' => $filter];
			}

			$all[$key] = $list;
			// Global size guard: reject if the whole value would exceed the cap.
			// Only the create path grows the value, so this is the single choke point.
			$encodedAll = json_encode($all);
			if ($encodedAll === false || strlen($encodedAll) > self::MAX_TOTAL_BYTES) {
				throw new InvalidInputException('Saved-filter storage limit reached.');
			}
			$this->writeAll($all, $encodedAll);
			return new JSONResponse(['filters' => $list]);
		});
	}

	/**
	 * Delete a saved filter by name. Idempotent (deleting a missing name is a
	 * no-op). Returns the remaining list for the board.
	 */
	#[NoAdminRequired]
	public function destroy(int $boardId, string $name): JSONResponse {
		return $this->respond(function () use ($boardId, $name): JSONResponse {
			$all = $this->readAll();
			$key = (string)$boardId;
			$list = $all[$key] ?? [];
			$list = array_values(array_filter($list, static fn ($v) => ($v['name'] ?? '') !== $name));
			if (empty($list)) {
				unset($all[$key]);
			} else {
				$all[$key] = $list;
			}
			$this->writeAll($all);
			return new JSONResponse(['filters' => $list]);
		});
	}

	/**
	 * Decode the whole saved-filters config value, tolerating a corrupt/legacy
	 * value by falling back to an empty map.
	 *
	 * @return array<string, list<array{name: string, filter: mixed}>>
	 */
	private function readAll(): array {
		$raw = $this->config->getUserValue($this->currentUserId(), 'kanso', self::KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<string, list<array{name: string, filter: mixed}>> $all
	 * @param ?string $encoded optional pre-encoded JSON (create path already has it)
	 */
	private function writeAll(array $all, ?string $encoded = null): void {
		$encoded ??= json_encode($all) ?: '{}';
		$this->config->setUserValue($this->currentUserId(), 'kanso', self::KEY, $encoded);
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

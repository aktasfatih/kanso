<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\ViewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Cross-board saved "Views" (#3815). A View is a named saved filter over ALL the
 * boards the user can read, listed in the left nav and opening a board-like
 * surface (List / Timeline) with a group-by selector.
 *
 * Persistence mirrors {@see SavedFilterController}: per-user NC user-config (app
 * 'kanso'), one key `saved_views` holding a JSON list of view records. The
 * `filter` blob is treated as OPAQUE by the server (the client owns its schema -
 * it is the same serialized board-filter shape the reused predicate consumes);
 * the server only enforces size / count caps and that names are unique. No new
 * table, no migration - a personal preference, never logged to the change feed.
 *
 * Storage shape:
 *   [ { "id": "…", "name": "…", "filter": { …opaque… }, "groupBy": "…", "display": "…",
 *       "sort": { "mode": "…", "dir": "asc|desc" } } ]
 *
 * A record saved before the sort control shipped has no `sort` key; {@see self::readAll()}
 * defaults it (and any unknown value) rather than rejecting it, so an older record
 * always loads. Sort is the ONE part of a View the server acts on - unlike `filter`,
 * it is applied server-side before the feed's cap.
 *
 * The cross-board card feed itself is served by {@see self::cards()} via
 * {@see ViewService::findMine()} - the server stays filter-agnostic and returns
 * the whole readable-set summaries; the client applies the View's filter and
 * grouping. ACL lives entirely in ViewService (readable-set one-query).
 */
class ViewController extends Controller {
	use ApiErrorTrait;

	private const KEY = 'saved_views';

	/** Hard caps to keep the user-config value bounded. */
	private const MAX_VIEWS = 100;
	private const MAX_NAME_LEN = 100;
	private const MAX_FILTER_BYTES = 4096;
	private const MAX_TOTAL_BYTES = 262144; // 256 KB

	/** The group-by fields the surface offers (mirrors the client selector / VIEW_GROUP_BY). */
	private const GROUP_BY = ['status', 'priority', 'assignee', 'board', 'type', 'review', 'due', 'owner'];

	/** The display modes the surface offers (Kanban groups the feed into columns by the group-by field). */
	private const DISPLAYS = ['list', 'timeline', 'kanban'];

	/**
	 * The sort modes a View can order its feed by. Applied SERVER-side in
	 * {@see ViewService::findMine()} before the cap, so a sorted View starts at the
	 * true first row. 'default' is the historical stable (boardId, id) order, so a
	 * View saved before the sort control looks unchanged until the user picks one.
	 *
	 * `manual` and `estimate` are deliberately NOT offered: manual is the per-stack
	 * fractional sort key (meaningless compared across boards) and estimate ranks
	 * against one board's estimate scale (a cross-board View can span two scales).
	 */
	private const SORT_MODES = ['default', 'due', 'priority', 'title', 'board', 'created', 'modified'];

	/** The sort directions. Missing values sort last in BOTH of them (see ViewService). */
	private const SORT_DIRS = ['asc', 'desc'];

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IConfig $config,
		private ViewService $viewService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the current user's saved views, in insertion order.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse(['views' => $this->readAll()]);
		});
	}

	/**
	 * Create or overwrite a saved view by name (upsert; names are unique). On a
	 * create a fresh opaque id is minted; on an update the existing id is kept.
	 * `filter` is the opaque client filter object; `groupBy` / `display` / `sort`
	 * are validated against the fixed offered sets. Returns the updated list.
	 */
	#[NoAdminRequired]
	public function create(string $name = '', mixed $filter = null, string $groupBy = 'status', string $display = 'list', mixed $sort = null): JSONResponse {
		return $this->respond(function () use ($name, $filter, $groupBy, $display, $sort): JSONResponse {
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
			if (!in_array($groupBy, self::GROUP_BY, true)) {
				throw new InvalidInputException('Unknown group-by field.');
			}
			if (!in_array($display, self::DISPLAYS, true)) {
				throw new InvalidInputException('Unknown display mode.');
			}
			$sortRecord = $this->validateSort($sort);
			// Bound the opaque blob so a huge payload can't bloat the user config.
			$encoded = json_encode($filter);
			if ($encoded === false || strlen($encoded) > self::MAX_FILTER_BYTES) {
				throw new InvalidInputException('Filter is too large.');
			}

			$list = $this->readAll();

			// Upsert by (case-sensitive) name.
			$found = false;
			foreach ($list as &$view) {
				if (($view['name'] ?? '') === $name) {
					$view['filter'] = $filter;
					$view['groupBy'] = $groupBy;
					$view['display'] = $display;
					$view['sort'] = $sortRecord;
					$found = true;
					break;
				}
			}
			unset($view);
			if (!$found) {
				if (count($list) >= self::MAX_VIEWS) {
					throw new InvalidInputException('Too many saved views.');
				}
				$list[] = [
					'id' => $this->mintId(),
					'name' => $name,
					'filter' => $filter,
					'groupBy' => $groupBy,
					'display' => $display,
					'sort' => $sortRecord,
				];
			}

			$this->persist($list);
			return new JSONResponse(['views' => $list]);
		});
	}

	/**
	 * Rename a saved view by id. The new name must be unique (case-sensitive)
	 * among the OTHER views. Returns the updated list.
	 */
	#[NoAdminRequired]
	public function rename(string $id, string $name = ''): JSONResponse {
		return $this->respond(function () use ($id, $name): JSONResponse {
			$name = trim($name);
			if ($name === '') {
				throw new InvalidInputException('A name is required.');
			}
			if (mb_strlen($name) > self::MAX_NAME_LEN) {
				$name = mb_substr($name, 0, self::MAX_NAME_LEN);
			}

			$list = $this->readAll();
			$target = null;
			foreach ($list as $view) {
				if (($view['id'] ?? '') === $id) {
					$target = $view;
				} elseif (($view['name'] ?? '') === $name) {
					throw new InvalidInputException('A view with that name already exists.');
				}
			}
			if ($target === null) {
				throw new InvalidInputException('View not found.');
			}

			foreach ($list as &$view) {
				if (($view['id'] ?? '') === $id) {
					$view['name'] = $name;
					break;
				}
			}
			unset($view);

			$this->persist($list);
			return new JSONResponse(['views' => $list]);
		});
	}

	/**
	 * Delete a saved view by id. Idempotent (deleting a missing id is a no-op).
	 * Returns the remaining list.
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		return $this->respond(function () use ($id): JSONResponse {
			$list = array_values(array_filter($this->readAll(), static fn ($v) => ($v['id'] ?? '') !== $id));
			$this->persist($list);
			return new JSONResponse(['views' => $list]);
		});
	}

	/**
	 * The cross-board card feed a View renders over: enriched card summaries from
	 * every board the user can read (ACL enforced in {@see ViewService}). The
	 * server stays filter-agnostic - the client applies the View's saved filter
	 * and group-by client-side, reusing the board filter predicate.
	 *
	 * Returns an envelope `{cards, capped, total, limit}` (see
	 * {@see ViewService::findMine()}): `cards` is hard-capped to keep this single
	 * unbounded feed bounded, and `capped`/`total` let the client honestly report
	 * when it is showing only the first N of M readable cards.
	 *
	 * The caller passes the View's saved sort, which is applied server-side BEFORE
	 * that cap so a sorted View starts at the true first row. Unknown values are
	 * ignored and defaulted here (never rejected) - this is a read path an older or
	 * newer client must never be able to hard-fail. The params are typed `mixed`
	 * for the same reason: a malformed query string (`?sortMode[]=due` hands the
	 * dispatcher an array) would otherwise be a TypeError thrown before the error
	 * wrapper can turn it into a response.
	 */
	#[NoAdminRequired]
	public function cards(mixed $sortMode = 'default', mixed $sortDir = 'asc'): JSONResponse {
		return $this->respond(function () use ($sortMode, $sortDir): JSONResponse {
			$sort = $this->normalizeSort(['mode' => $sortMode, 'dir' => $sortDir]);
			return new JSONResponse($this->viewService->findMine($this->currentUserId(), $sort['mode'], $sort['dir']));
		});
	}

	/**
	 * Decode the whole saved-views config value, tolerating a corrupt/legacy
	 * value by falling back to an empty list. Every record's `sort` is normalized
	 * on the way out, so a record written before the sort control shipped (no
	 * `sort` key) - or one carrying a value this version doesn't know - loads with
	 * the default sort instead of failing.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function readAll(): array {
		$raw = $this->config->getUserValue($this->currentUserId(), 'kanso', self::KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$list = [];
		foreach ($decoded as $view) {
			if (!is_array($view)) {
				continue;
			}
			$view['sort'] = $this->normalizeSort($view['sort'] ?? null);
			$list[] = $view;
		}
		return $list;
	}

	/**
	 * Validate an EXPLICIT sort from a write request. Unknown values are rejected
	 * here - the same contract `groupBy` / `display` already have, so a client
	 * typo surfaces instead of being silently swallowed. A missing sort defaults.
	 *
	 * @return array{mode: string, dir: string}
	 * @throws InvalidInputException
	 */
	private function validateSort(mixed $sort): array {
		if ($sort === null) {
			return ['mode' => 'default', 'dir' => 'asc'];
		}
		if (!is_array($sort)) {
			throw new InvalidInputException('Unknown sort.');
		}
		$mode = $sort['mode'] ?? 'default';
		$dir = $sort['dir'] ?? 'asc';
		if (!is_string($mode) || !in_array($mode, self::SORT_MODES, true)) {
			throw new InvalidInputException('Unknown sort mode.');
		}
		if (!is_string($dir) || !in_array($dir, self::SORT_DIRS, true)) {
			throw new InvalidInputException('Unknown sort direction.');
		}
		return ['mode' => $mode, 'dir' => $dir];
	}

	/**
	 * Normalize a sort read back from storage (or from the feed's query string):
	 * anything missing or unrecognized becomes the default. Never throws - a saved
	 * record, however old, must always load.
	 *
	 * @return array{mode: string, dir: string}
	 */
	private function normalizeSort(mixed $sort): array {
		$mode = is_array($sort) ? ($sort['mode'] ?? null) : null;
		$dir = is_array($sort) ? ($sort['dir'] ?? null) : null;
		return [
			'mode' => (is_string($mode) && in_array($mode, self::SORT_MODES, true)) ? $mode : 'default',
			'dir' => (is_string($dir) && in_array($dir, self::SORT_DIRS, true)) ? $dir : 'asc',
		];
	}

	/**
	 * Encode + size-guard + write the whole list. The global cap bounds the whole
	 * user-config row regardless of view count.
	 *
	 * @param list<array<string, mixed>> $list
	 */
	private function persist(array $list): void {
		$encoded = json_encode($list);
		if ($encoded === false || strlen($encoded) > self::MAX_TOTAL_BYTES) {
			throw new InvalidInputException('Saved-view storage limit reached.');
		}
		$this->config->setUserValue($this->currentUserId(), 'kanso', self::KEY, $encoded);
	}

	/**
	 * A short opaque id for a new view. Random, so it never collides with an
	 * existing one and carries no ordering/enumeration meaning.
	 */
	private function mintId(): string {
		return bin2hex(random_bytes(8));
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

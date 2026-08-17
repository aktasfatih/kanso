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
 *   [ { "id": "…", "name": "…", "filter": { …opaque… }, "groupBy": "…", "display": "…" } ]
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

	/** The display modes the surface offers (Kanban is a separate Phase 2 card). */
	private const DISPLAYS = ['list', 'timeline'];

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
	 * `filter` is the opaque client filter object; `groupBy` / `display` are
	 * validated against the fixed offered sets. Returns the updated list.
	 */
	#[NoAdminRequired]
	public function create(string $name = '', mixed $filter = null, string $groupBy = 'status', string $display = 'list'): JSONResponse {
		return $this->respond(function () use ($name, $filter, $groupBy, $display): JSONResponse {
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
	 */
	#[NoAdminRequired]
	public function cards(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->viewService->findMine($this->currentUserId()));
		});
	}

	/**
	 * Decode the whole saved-views config value, tolerating a corrupt/legacy
	 * value by falling back to an empty list.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function readAll(): array {
		$raw = $this->config->getUserValue($this->currentUserId(), 'kanso', self::KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values($decoded) : [];
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

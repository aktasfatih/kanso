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
 * Cross-board saved "Views" (#3815).
 *
 * A View is a named, BOARD-AGNOSTIC saved filter (the same client-side filter-bar
 * dimensions as a board's saved filter, but not tied to any one board) that runs
 * across every board the user can read. Like {@see SavedFilterController} (#3407)
 * — its sibling and template — a View is a pure personal preference (no board
 * data, no ACL implication in the STORED value), so it lives in NC user config
 * (app 'kanso') rather than in a dedicated table: no migration, no new mapper.
 *
 * Storage shape: one user-config key per user, `saved_views`, holding a FLAT JSON
 * array of named views (no board scoping, unlike saved_filters which is keyed by
 * board id):
 *   [ { "name": "…", "filter": { … opaque client filter … } } ]
 *
 * The `filter` blob is opaque to the server: the client owns its schema, the
 * server only enforces size / count caps and that names are unique. Running a
 * View over the readable card set lives in {@see ViewService::findMine} — this
 * controller only persists the definitions.
 */
class ViewController extends Controller {
	use ApiErrorTrait;

	private const KEY = 'saved_views';

	/** Hard caps to keep the user-config value bounded (mirrors SavedFilterController). */
	private const MAX_VIEWS = 50;
	private const MAX_NAME_LEN = 100;
	private const MAX_FILTER_BYTES = 4096;

	// Global cap on the ENTIRE per-user value, so a scripted client cannot bloat
	// the row regardless of view count.
	private const MAX_TOTAL_BYTES = 262144; // 256 KB

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
	 * Create or overwrite a saved view by name (upsert; names are unique).
	 * `filter` is the opaque client filter object. Returns the updated list.
	 */
	#[NoAdminRequired]
	public function create(string $name = '', mixed $filter = null): JSONResponse {
		return $this->respond(function () use ($name, $filter): JSONResponse {
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
			// user config. json_encode of the filter, size-checked.
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
					$found = true;
					break;
				}
			}
			unset($view);
			if (!$found) {
				if (count($list) >= self::MAX_VIEWS) {
					throw new InvalidInputException('Too many saved views.');
				}
				$list[] = ['name' => $name, 'filter' => $filter];
			}

			// Global size guard: reject if the whole value would exceed the cap.
			$encodedAll = json_encode($list);
			if ($encodedAll === false || strlen($encodedAll) > self::MAX_TOTAL_BYTES) {
				throw new InvalidInputException('Saved-view storage limit reached.');
			}
			$this->writeAll($list, $encodedAll);
			return new JSONResponse(['views' => $list]);
		});
	}

	/**
	 * Delete a saved view by name. Idempotent (deleting a missing name is a
	 * no-op). Returns the remaining list.
	 */
	#[NoAdminRequired]
	public function destroy(string $name): JSONResponse {
		return $this->respond(function () use ($name): JSONResponse {
			$list = $this->readAll();
			$list = array_values(array_filter($list, static fn ($v) => ($v['name'] ?? '') !== $name));
			$this->writeAll($list);
			return new JSONResponse(['views' => $list]);
		});
	}

	/**
	 * Run a View over the readable card set: every card the current user can read,
	 * across every board they can read, as full predicate-ready summaries. The
	 * CLIENT applies the View's predicate — the server stays filter-agnostic.
	 */
	#[NoAdminRequired]
	public function cards(): JSONResponse {
		return $this->respond(function (): JSONResponse {
			return new JSONResponse($this->viewService->findMine($this->currentUserId()));
		});
	}

	/**
	 * Decode the whole saved-views config value, tolerating a corrupt/legacy value
	 * by falling back to an empty list.
	 *
	 * @return list<array{name: string, filter: mixed}>
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
	 * @param list<array{name: string, filter: mixed}> $list
	 * @param ?string $encoded optional pre-encoded JSON (create path already has it)
	 */
	private function writeAll(array $list, ?string $encoded = null): void {
		$encoded ??= json_encode($list) ?: '[]';
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

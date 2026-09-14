<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\AppInfo\Application;
use OCA\Kanso\Service\UserSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private UserSettingsService $userSettings,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->provideUserSettings();
		$this->addMainScript();
		return new TemplateResponse(Application::APP_ID, 'main');
	}

	/**
	 * Queues the SPA bundle for the app shell. A seam, mirroring
	 * {@see DeepLinkController::addMainScript()}: Util::addScript needs the full
	 * server (\OC) at runtime, so the unit test overrides this no-op-style.
	 */
	protected function addMainScript(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
	}

	/**
	 * Embeds the user's view preferences in the app shell (#10460).
	 *
	 * Without this the SPA mounts with the hardcoded defaults and only learns the
	 * user's real choices when the async GET /api/settings resolves - so a card
	 * opened by link visibly snapped from the default layout to the chosen one.
	 * The preferences are plain `IConfig::getUserValue` reads (one preferences
	 * load, already part of the request), so this adds no round-trip.
	 *
	 * The seed is a head start, NOT the source of truth: the SPA still fetches
	 * /api/settings and reconciles. That matters because the service worker
	 * caches the app shell under one stable key, so an offline boot can carry the
	 * preferences as of the last online load. Only view preferences travel here -
	 * no board or card data - so a stale shell is at worst the wrong layout for
	 * one paint, which the fetch then corrects.
	 */
	private function provideUserSettings(): void {
		if ($this->userId === null) {
			// Unreachable behind #[NoAdminRequired] (the auth middleware redirects
			// first); the SPA falls back to its defaults + the settings fetch.
			return;
		}
		$this->initialState->provideInitialState('settings', $this->userSettings->readAll($this->userId));
	}

	/**
	 * The service worker script — the one piece Nextcloud does NOT provide for a
	 * PWA. Nextcloud's theming app already injects an app-scoped web manifest
	 * (/apps/theming/manifest/kanso, with themed colours + a rasterised icon) and
	 * all the apple-mobile-web-app / theme-color meta tags, so Kanso adds no
	 * manifest or meta of its own; it only needs a service worker (with a fetch
	 * handler) to become installable and work offline.
	 *
	 * Served from /apps/kanso/sw.js (NOT under js/) so its default scope is the
	 * whole app — see templates/sw.js for why it is a PHP-delivered classic worker
	 * rather than a Vite bundle. Public + CSRF-free (a worker script has no
	 * session). `Service-Worker-Allowed` is set defensively to the app scope;
	 * `no-cache` lets browsers pick up an updated worker promptly.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function serviceWorker(): DataDisplayResponse {
		$scope = $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index');
		$body = @file_get_contents(dirname(__DIR__, 2) . '/templates/sw.js');
		if ($body === false) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
		}

		$response = new DataDisplayResponse(
			$body,
			Http::STATUS_OK,
			[
				'Content-Type' => 'text/javascript; charset=UTF-8',
				'Service-Worker-Allowed' => $scope,
				'Cache-Control' => 'no-cache',
			],
		);

		// A service worker's fetch()es are governed by the CSP of the WORKER SCRIPT
		// response, not the page. Nextcloud's default response CSP is
		// `default-src 'none'` with no connect-src, which blocks every fetch the
		// worker makes — so navigations it controls fail with ERR_FAILED. Allow the
		// worker to connect to its own origin (all Kanso assets + API are
		// same-origin); the default ContentSecurityPolicy sets connect-src 'self'.
		$response->setContentSecurityPolicy(new ContentSecurityPolicy());
		return $response;
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		return new TemplateResponse(Application::APP_ID, 'main');
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

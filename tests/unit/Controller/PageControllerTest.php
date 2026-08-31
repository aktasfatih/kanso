<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\PageController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the PWA plumbing (#mobile-pwa). Both cases below encode
 * bugs that shipped once and broke the installable PWA in real browsers while
 * the e2e smoke test stayed green:
 *  - the service worker was served with Nextcloud's default `default-src 'none'`
 *    CSP, which blocked every fetch() the worker made (→ ERR_FAILED), and
 *  - the app icon carried a leading XML comment that made Imagick fail to decode
 *    it, so theming couldn't render the manifest icon (→ Chrome refused install).
 */
class PageControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IURLGenerator&MockObject $urlGenerator;
	private PageController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/kanso/');
		$this->controller = new PageController('kanso', $this->request, $this->urlGenerator);
	}

	public function testServiceWorkerCarriesACspThatLetsTheWorkerFetch(): void {
		$response = $this->controller->serviceWorker();

		// The script must actually be found + served (a 404 here would mean the
		// templates/sw.js the controller streams went missing).
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		// A service worker inherits the CSP of its own script response. Without an
		// explicit policy Nextcloud applies `default-src 'none'` (no connect-src),
		// which blocks EVERY fetch the worker makes and breaks every navigation it
		// controls. The response must carry a policy that allows connecting to its
		// own origin.
		$csp = $response->getContentSecurityPolicy();
		$this->assertInstanceOf(ContentSecurityPolicy::class, $csp);
		$policy = $csp->buildPolicy();
		$this->assertStringContainsString('connect-src', $policy);
		$this->assertStringContainsString("'self'", $policy);
	}

	public function testAppIconStartsWithSvgSoThemingCanRasteriseIt(): void {
		// A leading `<!-- … -->` SPDX comment before the root <svg> makes Imagick
		// report "no decode delegate", so Nextcloud's theming can't render the app
		// icon and the PWA manifest icon 404s (blocking install). Licensing for
		// this file lives in REUSE.toml precisely so the file can start with <svg>.
		$svg = ltrim((string)file_get_contents(dirname(__DIR__, 3) . '/img/app.svg'));

		$this->assertTrue(
			str_starts_with($svg, '<svg') || str_starts_with($svg, '<?xml'),
			'img/app.svg must start with <svg> (or <?xml>) — a leading comment breaks Imagick/theming icon generation'
		);
	}
}

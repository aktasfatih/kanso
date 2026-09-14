<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\PageController;
use OCA\Kanso\Service\UserSettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the PWA plumbing (#mobile-pwa) and for the view
 * preferences the app shell carries (#10460). The PWA cases encode bugs that
 * shipped once and broke the installable PWA in real browsers while the e2e
 * smoke test stayed green:
 *  - the service worker was served with Nextcloud's default `default-src 'none'`
 *    CSP, which blocked every fetch() the worker made (→ ERR_FAILED), and
 *  - the app icon carried a leading XML comment that made Imagick fail to decode
 *    it, so theming couldn't render the manifest icon (→ Chrome refused install).
 */
class PageControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IConfig&MockObject $config;
	private IInitialState&MockObject $initialState;
	private IURLGenerator&MockObject $urlGenerator;
	private PageController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/kanso/');
		$this->controller = $this->controllerFor('alice');
	}

	private function controllerFor(?string $userId): PageController {
		// Anonymous subclass overriding the addMainScript() seam:
		// Util::addScript needs the full \OC server, absent in unit tests.
		$settings = new UserSettingsService($this->config);
		$args = ['kanso', $this->request, $userId, $settings, $this->initialState, $this->urlGenerator];
		return new class(...$args) extends PageController {
			#[\Override]
			protected function addMainScript(): void {
				// no-op in unit tests
			}
		};
	}

	/**
	 * The app shell carries the user's view preferences (#10460). Without them
	 * the SPA mounts with its hardcoded defaults and only learns the real ones
	 * when the async GET /api/settings resolves — so a card opened by link
	 * visibly snapped from the default layout to the chosen one.
	 */
	public function testIndexSeedsTheShellWithTheUsersViewPreferences(): void {
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default): string {
				self::assertSame('alice', $uid);
				self::assertSame('kanso', $app);
				return $key === 'card_discussion_position' ? 'bottom' : $default;
			});

		$provided = null;
		$this->initialState->expects(self::once())
			->method('provideInitialState')
			->willReturnCallback(static function (string $key, mixed $value) use (&$provided): void {
				self::assertSame('settings', $key);
				$provided = $value;
			});

		$response = $this->controller->index();

		self::assertSame('main', $response->getTemplateName());
		self::assertSame('bottom', $provided['cardDiscussionPosition']);
		// The whole payload travels, so every flash-prone preference is seeded.
		self::assertFalse($provided['editorToolbarHidden']);
		self::assertArrayHasKey('defaultBoardId', $provided);
	}

	/**
	 * Defense-in-depth: the route requires a login, but a session that evaporated
	 * mid-request must render the shell rather than reading preferences for no
	 * user at all.
	 */
	public function testIndexWithoutASessionSeedsNothingAndStillRendersTheShell(): void {
		$this->config->expects(self::never())->method('getUserValue');
		$this->initialState->expects(self::never())->method('provideInitialState');

		self::assertSame('main', $this->controllerFor(null)->index()->getTemplateName());
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

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\DeepLinkController;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\UserSettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The fragment-free card deep link (#3744): a visible card renders the app
 * shell with the `openCard` initial state; EVERY failure (missing card,
 * hidden card, non-member) renders the same 404 page - the route must not be
 * an existence oracle.
 */
class DeepLinkControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private CardService&MockObject $cardService;
	private IConfig&MockObject $config;
	private IInitialState&MockObject $initialState;
	private IURLGenerator&MockObject $urlGenerator;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/kanso/');
	}

	private function controller(?string $userId): DeepLinkController {
		// Anonymous subclass overriding the addMainScript() seam:
		// Util::addScript needs the full \OC server, absent in unit tests.
		$settings = new UserSettingsService($this->config);
		$args = ['kanso', $this->request, $userId, $this->cardService, $settings, $this->initialState, $this->urlGenerator];
		return new class(...$args) extends DeepLinkController {
			#[\Override]
			protected function addMainScript(): void {
				// no-op in unit tests
			}
		};
	}

	public function testVisibleCardRendersAppShellWithOpenCardState(): void {
		$card = new Card();
		$card->setId(9);
		$card->setBoardId(3);
		// The card load runs the full API authorization (READ + visibility).
		$this->cardService->expects(self::once())->method('find')->with(9, 'bob')->willReturn($card);
		// No preferences stored: every key reads back as its own default.
		$this->config->method('getUserValue')->willReturnArgument(3);
		$provided = $this->captureInitialState();

		$response = $this->controller('bob')->card(9);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('main', $response->getTemplateName());
		self::assertSame(['boardId' => 3, 'cardId' => 9], $provided['openCard'] ?? null);
	}

	/**
	 * A deep-linked card opens immediately, so it is the loudest case of the
	 * settings flash (#10460): the SPA used to mount with the hardcoded layout
	 * defaults and snap to the user's real choice once GET /api/settings
	 * resolved. The shell carries the preferences so the first paint is right.
	 */
	public function testVisibleCardAlsoSeedsTheUsersViewPreferences(): void {
		$card = new Card();
		$card->setId(9);
		$card->setBoardId(3);
		$this->cardService->method('find')->willReturn($card);
		$this->config->method('getUserValue')
			->willReturnCallback(static function (string $uid, string $app, string $key, string $default): string {
				self::assertSame('bob', $uid);
				return $key === 'card_discussion_position' ? 'bottom' : $default;
			});
		$provided = $this->captureInitialState();

		$this->controller('bob')->card(9);

		self::assertSame('bottom', $provided['settings']['cardDiscussionPosition'] ?? null);
		self::assertFalse($provided['settings']['editorToolbarHidden'] ?? null);
	}

	/**
	 * Records every provideInitialState() call by key into an object the test can
	 * read after the controller has run (the controller provides more than one
	 * key now, so `->with(...)` on a single expectation no longer fits).
	 *
	 * @return \ArrayObject<string, mixed>
	 */
	private function captureInitialState(): \ArrayObject {
		/** @var \ArrayObject<string, mixed> $provided */
		$provided = new \ArrayObject();
		$this->initialState->method('provideInitialState')
			->willReturnCallback(static function (string $key, mixed $value) use ($provided): void {
				$provided[$key] = $value;
			});
		return $provided;
	}

	public function testMissingCardRendersNotFoundPage(): void {
		$this->cardService->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->initialState->expects(self::never())->method('provideInitialState');

		$response = $this->controller('bob')->card(999);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('card-notfound', $response->getTemplateName());
	}

	public function testNonMemberGetsTheSameNotFoundShapeNotA403(): void {
		// A 403 would confirm the card id exists; the deep link must answer a
		// non-member exactly like a card that never existed.
		$this->cardService->method('find')->willThrowException(new NotPermittedException('denied'));
		$this->initialState->expects(self::never())->method('provideInitialState');

		$response = $this->controller('eve')->card(9);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('card-notfound', $response->getTemplateName());
	}

	public function testMissingSessionRendersNotFoundWithoutTouchingTheCard(): void {
		// Defense-in-depth: unauthenticated requests are redirected to login
		// before the controller runs, but a null user must still never probe.
		$this->cardService->expects(self::never())->method('find');

		$response = $this->controller(null)->card(9);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}

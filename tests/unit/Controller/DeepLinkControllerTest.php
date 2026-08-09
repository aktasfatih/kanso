<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\DeepLinkController;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
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
	private IInitialState&MockObject $initialState;
	private IURLGenerator&MockObject $urlGenerator;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/kanso/');
	}

	private function controller(?string $userId): DeepLinkController {
		// Anonymous subclass overriding the addMainScript() seam:
		// Util::addScript needs the full \OC server, absent in unit tests.
		$args = ['kanso', $this->request, $userId, $this->cardService, $this->initialState, $this->urlGenerator];
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
		$this->initialState->expects(self::once())
			->method('provideInitialState')
			->with('openCard', ['boardId' => 3, 'cardId' => 9]);

		$response = $this->controller('bob')->card(9);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('main', $response->getTemplateName());
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

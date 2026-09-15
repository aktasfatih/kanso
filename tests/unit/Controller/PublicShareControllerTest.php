<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\PublicShareController;
use OCA\Kanso\Service\PublicShareExpiredException;
use OCA\Kanso\Service\PublicShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * #10466 - what an anonymous visitor is TOLD when a public board link does not
 * work.
 *
 * Every rejection used to render one page: "invalid or has been disabled". A
 * recipient whose link had simply run out could not tell that from a mistyped
 * URL, so the support path was "ask the owner to re-check the address" for a
 * link that was working perfectly and had merely expired.
 *
 * The expired case now has its own page. The security posture around it is what
 * these cases actually pin, because it is the part that is easy to regress:
 *  - the HTTP status stays 404 (never 410 or 200) and the route stays throttled,
 *    so the token space is no more enumerable than it was, and
 *  - EVERY other reason - unknown token, disabled board, rotated token - still
 *    gets the single indistinguishable page.
 */
class PublicShareControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private PublicShareService&MockObject $publicShareService;
	private PublicShareController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->publicShareService = $this->createMock(PublicShareService::class);
		$this->controller = new PublicShareController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->publicShareService,
		);
	}

	public function testAnExpiredLinkGetsAPageThatNamesExpiryAsTheCause(): void {
		$this->publicShareService->method('assertTokenValid')
			->willThrowException(new PublicShareExpiredException('Public share has expired'));

		$response = $this->controller->show('a-real-but-expired-token');
		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame('public-expired', $response->getTemplateName());
		// Same status and same throttle as every other rejection: the message is
		// the ONLY thing that differs.
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertTrue($response->isThrottled());
	}

	/**
	 * The generic page must keep covering everything else. PublicShareExpiredException
	 * is a SUBCLASS of DoesNotExistException, so the catch arms are order-sensitive -
	 * swap them and this case would start rendering the expired page for a token that
	 * never existed, which IS the enumeration oracle the generic page avoids.
	 */
	public function testEveryOtherRejectionStillGetsTheIndistinguishablePage(): void {
		$this->publicShareService->method('assertTokenValid')
			->willThrowException(new DoesNotExistException('no such token'));

		$response = $this->controller->show('totally-made-up-token');
		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame('public-notfound', $response->getTemplateName());
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertTrue($response->isThrottled());
	}

	/**
	 * #10379 item A. The JSON payload route's REJECTION is throttled - the same
	 * defence of the token space the two page cases above pin, on the route an
	 * enumerator would actually script, and previously pinned nowhere.
	 */
	public function testARejectedAnonymousPayloadReadIsThrottled(): void {
		$this->publicShareService->method('getPublicBoard')
			->willThrowException(new DoesNotExistException('no such token'));

		$response = $this->controller->data('totally-made-up-token');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertTrue($response->isThrottled());
	}

	/**
	 * And the other half of that decision, which is the one at risk of being
	 * "fixed" the wrong way: a SUCCESSFUL anonymous read must NOT be throttled.
	 *
	 * `#[BruteForceProtection]` is a failure counter, not a rate limiter. Calling
	 * `throttle()` here would have BruteForceMiddleware register an attempt per
	 * successful VIEW, and Nextcloud's throttler answers the 5th attempt from an
	 * address with a ~3s sleep, the 8th with the 25s cap and the 11th inside 30
	 * minutes with a flat 429 - against readers of a link they were handed, who
	 * behind one NAT share one address. The reasoning is on
	 * {@see PublicShareController::data()}; this is the guard.
	 *
	 * It lives in PHPUnit rather than the e2e suite on purpose: the dev stack and
	 * CI both run with `auth.bruteforce.protection.enabled=false` (dev/setup.sh),
	 * so a browser-level "many reads in a row still answer 200" assertion would
	 * pass with the throttle call present and prove nothing. `isThrottled()` is
	 * the controller's own decision and is readable whatever the instance config.
	 */
	public function testASuccessfulAnonymousReadIsNotThrottled(): void {
		$this->publicShareService->method('getPublicBoard')
			->willReturn(['board' => [], 'stacks' => [], 'cards' => []]);

		$response = $this->controller->data('a-real-live-token');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse(
			$response->isThrottled(),
			'A successful public-share read must not register a brute-force attempt - '
			. 'it would rate-limit legitimate viewers of a popular shared board.',
		);
	}
}

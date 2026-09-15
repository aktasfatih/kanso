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
}

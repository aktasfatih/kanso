<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCA\Kanso\Service\CalendarFeedService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The public ICS feed is CONDITIONAL (#4 of the app's performance bets, reused
 * here rather than reinvented): the board's latest change id is the ETag, and a
 * calendar client replaying it in `If-None-Match` gets a 304 before a single card
 * is read or serialised. That is what makes a fleet of scheduled pollers cheap -
 * a per-IP rate limit could not, because an office behind one NAT shares an
 * address with every one of its clients.
 */
class CalendarFeedControllerTest extends TestCase {
	private const TOKEN = 'a-very-long-unguessable-ical-token-value-0123456789';

	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private CalendarFeedService&MockObject $feedService;
	private CalendarFeedController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->feedService = $this->createMock(CalendarFeedService::class);
		$this->controller = new CalendarFeedController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->feedService,
		);
	}

	public function testMatchingIfNoneMatchIs304WithoutRenderingTheCalendar(): void {
		$this->feedService->method('feedEtag')->with(self::TOKEN)->willReturn('7');
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"7"');
		// The whole point: the expensive path is never entered.
		$this->feedService->expects(self::never())->method('renderFeed');

		$response = $this->controller->feed(self::TOKEN);

		self::assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		self::assertSame('7', $response->getETag());
	}

	public function testWeakValidatorAlsoMatches(): void {
		$this->feedService->method('feedEtag')->willReturn('7');
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('W/"7"');
		$this->feedService->expects(self::never())->method('renderFeed');

		self::assertSame(Http::STATUS_NOT_MODIFIED, $this->controller->feed(self::TOKEN)->getStatus());
	}

	public function testStaleIfNoneMatchStillServesTheCalendarWithTheFreshETag(): void {
		$this->feedService->method('feedEtag')->willReturn('7');
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"3"');
		$this->feedService->expects(self::once())->method('renderFeed')->with(self::TOKEN)
			->willReturn("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

		$response = $this->controller->feed(self::TOKEN);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertStringContainsString('BEGIN:VCALENDAR', $response->render());
		// The fresh validator ships with the body, so the NEXT poll can be a 304.
		self::assertSame('7', $response->getETag());
	}

	public function testFirstFetchWithNoValidatorServesTheCalendar(): void {
		$this->feedService->method('feedEtag')->willReturn('7');
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('');
		$this->feedService->expects(self::once())->method('renderFeed')
			->willReturn("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

		$response = $this->controller->feed(self::TOKEN);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('7', $response->getETag());
	}

	public function testUnknownTokenIsAThrottled404EvenOnAConditionalRequest(): void {
		// The conditional probe must be no better an oracle than a plain fetch:
		// same 404, same throttle, whatever the client sent as a validator.
		$this->feedService->method('feedEtag')
			->willThrowException(new DoesNotExistException('no such token'));
		$this->request->method('getHeader')->with('If-None-Match')->willReturn('"7"');
		$this->feedService->expects(self::never())->method('renderFeed');

		$response = $this->controller->feed('does-not-exist');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('', $response->render());
	}
}

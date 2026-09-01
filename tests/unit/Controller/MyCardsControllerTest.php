<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\MyCardsController;
use OCA\Kanso\Service\MyCardsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The "My tasks" feed endpoint. The body stays the plain card list every API
 * client already consumes; the server-side cap is reported in headers so a
 * client can show "showing the first N" and a "N+" badge instead of presenting
 * a truncated window as somebody's whole workload.
 */
class MyCardsControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private MyCardsService&MockObject $myCardsService;
	private MyCardsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->myCardsService = $this->createMock(MyCardsService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new MyCardsController('kanso', $this->request, $this->userSession, $this->myCardsService);
	}

	/**
	 * The headers the controller set. Response::getHeaders() merges in the
	 * framework defaults via the server container (absent in unit tests), so
	 * read the response's own header map directly.
	 *
	 * @return array<string, string>
	 */
	private function headersOf(Response $response): array {
		$headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
		return is_array($headers) ? $headers : [];
	}

	public function testCompleteFeedReturnsTheCardListAndSaysItIsNotTruncated(): void {
		$cards = [['id' => 1, 'boardId' => 3, 'title' => 'A task']];
		$this->myCardsService->method('findMine')->with('alice')->willReturn([
			'cards' => $cards,
			'truncated' => false,
			'limit' => 200,
		]);

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		// The documented contract: a bare list, not an envelope.
		self::assertSame($cards, $response->getData());
		self::assertSame('0', $this->headersOf($response)[MyCardsController::HEADER_TRUNCATED]);
		self::assertSame('200', $this->headersOf($response)[MyCardsController::HEADER_LIMIT]);
	}

	public function testTruncatedFeedIsSignalledInTheHeaders(): void {
		// The cap must never be silent: without this header the client cannot
		// tell "you have 200 tasks" from "here are the first 200 of many".
		$this->myCardsService->method('findMine')->willReturn([
			'cards' => array_fill(0, 200, ['id' => 1]),
			'truncated' => true,
			'limit' => 200,
		]);

		$response = $this->controller->index();

		self::assertSame('1', $this->headersOf($response)[MyCardsController::HEADER_TRUNCATED]);
		self::assertSame('200', $this->headersOf($response)[MyCardsController::HEADER_LIMIT]);
	}

	public function testNoSessionIsDeniedWithoutQueryingTheFeed(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$service = $this->createMock(MyCardsService::class);
		$service->expects(self::never())->method('findMine');

		$response = (new MyCardsController('kanso', $this->request, $session, $service))->index();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}

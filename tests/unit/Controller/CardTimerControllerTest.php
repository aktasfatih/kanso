<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\CardTimerController;
use OCA\Kanso\Db\CardRunningTimer;
use OCA\Kanso\Service\CardTimerService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardTimerControllerTest extends TestCase {
	private CardTimerService&MockObject $service;
	private IUserSession&MockObject $session;
	private CardTimerController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(CardTimerService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->controller = new CardTimerController('kanso', $this->createMock(IRequest::class), $this->session, $this->service);
	}

	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}

	public function testStartForwardsTheAuthenticatedUserAndAiAttribution(): void {
		$this->authenticate();
		$timer = new CardRunningTimer();
		$this->service->expects(self::once())->method('start')->with(9, 'alice', 'Codex')->willReturn($timer);
		$response = $this->controller->start(9, 'Codex');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($timer, $response->getData());
	}

	public function testStopIsIdempotentWhenNoTimerIsRunning(): void {
		$this->authenticate();
		$this->service->expects(self::once())->method('stop')->with(9, 'alice')->willReturn(null);
		self::assertNull($this->controller->stop(9)->getData());
	}

	public function testNoAuthenticatedUserCannotStartATimer(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method('start');
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->start(9)->getStatus());
	}

	public function testEditDenialMapsToForbidden(): void {
		$this->authenticate();
		$this->service->method('stop')->willThrowException(new NotPermittedException());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->stop(9)->getStatus());
	}

	public function testAiGateFailureMapsToBadRequest(): void {
		$this->authenticate();
		$this->service->method('start')->willThrowException(new InvalidInputException('This board does not allow AI actors'));
		$response = $this->controller->start(9, 'Codex');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'This board does not allow AI actors'], $response->getData());
	}
}

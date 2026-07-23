<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\StackController;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\StackService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StackControllerTest extends TestCase {
	private StackService&MockObject $stackService;
	private StackController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->stackService = $this->createMock(StackService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new StackController(
			'kanso',
			$request,
			$userSession,
			$this->stackService
		);
	}

	private function stack(int $id = 5): Stack {
		$stack = new Stack();
		$stack->setId($id);
		$stack->setBoardId(1);
		$stack->setTitle('To do');
		$stack->setSortKey('I');
		return $stack;
	}

	public function testCreateReturnsStack(): void {
		$stack = $this->stack();
		$this->stackService->method('create')->with(1, 'To do', 'alice')->willReturn($stack);

		$response = $this->controller->create(1, 'To do');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($stack, $response->getData());
	}

	public function testCreateMapsNotPermittedTo403(): void {
		$this->stackService->method('create')->willThrowException(new NotPermittedException());

		$response = $this->controller->create(1, 'To do');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateReturnsStack(): void {
		$stack = $this->stack();
		$this->stackService->method('update')->with(5, 'Renamed', null, null, null, 'alice')->willReturn($stack);

		$response = $this->controller->update(5, 'Renamed');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($stack, $response->getData());
	}

	public function testUpdateMapsNotPermittedTo403(): void {
		$this->stackService->method('update')->willThrowException(new NotPermittedException());

		$response = $this->controller->update(5, 'Renamed');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdateMapsDoesNotExistTo404(): void {
		$this->stackService->method('update')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->update(5, 'Renamed');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDestroyReturnsStack(): void {
		$stack = $this->stack();
		$this->stackService->method('delete')->with(5, 'alice')->willReturn($stack);

		$response = $this->controller->destroy(5);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($stack, $response->getData());
	}

	public function testDestroyMapsNotPermittedTo403(): void {
		$this->stackService->method('delete')->willThrowException(new NotPermittedException());

		$response = $this->controller->destroy(5);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testMoveReturnsStack(): void {
		$stack = $this->stack();
		$this->stackService->method('move')->with(5, 3, 'alice')->willReturn($stack);

		$response = $this->controller->move(5, 3);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($stack, $response->getData());
	}

	public function testMoveMapsNotPermittedTo403(): void {
		$this->stackService->method('move')->willThrowException(new NotPermittedException());

		$response = $this->controller->move(5, null);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testMoveMapsOverflowTo409(): void {
		$this->stackService->method('move')->willThrowException(new \OverflowException('rebalance'));

		$response = $this->controller->move(5, 3);
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['error' => 'rebalance_required'], $response->getData());
	}
}

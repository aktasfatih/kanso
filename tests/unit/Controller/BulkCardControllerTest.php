<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\BulkCardController;
use OCA\Kanso\Service\BulkCardService;
use OCA\Kanso\Service\InvalidInputException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BulkCardControllerTest extends TestCase {
	private BulkCardService&MockObject $bulkCardService;
	private BulkCardController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->bulkCardService = $this->createMock(BulkCardService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new BulkCardController(
			'kanso',
			$request,
			$userSession,
			$this->bulkCardService,
		);
	}

	public function testApplyReturnsPerCardSummary(): void {
		$summary = ['ok' => [11, 12], 'skipped' => [['id' => 13, 'reason' => 'forbidden']]];
		$this->bulkCardService->expects(self::once())
			->method('apply')
			->with([11, 12, 13], BulkCardService::ACTION_MOVE, ['targetStackId' => 6], 'alice')
			->willReturn($summary);

		$response = $this->controller->apply([11, 12, 13], BulkCardService::ACTION_MOVE, ['targetStackId' => 6]);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($summary, $response->getData());
	}

	public function testApplyMapsInvalidInputTo400(): void {
		$this->bulkCardService->method('apply')
			->willThrowException(new InvalidInputException('No cards selected'));

		$response = $this->controller->apply([], BulkCardService::ACTION_ARCHIVE, []);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('No cards selected', $response->getData()['error']);
	}

	public function testApplyDefaultsAreSafeForAnEmptyBody(): void {
		// An empty POST body maps every param to its default; the service is still
		// invoked (and would 400 on the empty list) rather than erroring in the
		// controller.
		$this->bulkCardService->method('apply')
			->with([], '', [], 'alice')
			->willThrowException(new InvalidInputException('No cards selected'));

		$response = $this->controller->apply();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}

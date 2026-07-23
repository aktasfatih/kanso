<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\LabelController;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LabelControllerTest extends TestCase {
	private LabelService&MockObject $labelService;
	private LabelController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->labelService = $this->createMock(LabelService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new LabelController(
			'kanso',
			$request,
			$userSession,
			$this->labelService
		);
	}

	private function label(int $id = 7): Label {
		$label = new Label();
		$label->setId($id);
		$label->setBoardId(1);
		$label->setTitle('Urgent');
		$label->setColor('FF0000');
		return $label;
	}

	public function testCreateReturnsLabel(): void {
		$label = $this->label();
		$this->labelService->method('create')
			->with(1, 'Urgent', 'FF0000', 'alice')
			->willReturn($label);

		$response = $this->controller->create(1, 'Urgent', 'FF0000');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($label, $response->getData());
	}

	public function testCreateMapsInvalidColorTo400(): void {
		$this->labelService->method('create')
			->willThrowException(new InvalidInputException('Color must be a 6-digit hex value without "#"'));

		$response = $this->controller->create(1, 'Urgent', '#FF0000');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Color must be a 6-digit hex value without "#"', $response->getData()['error']);
	}

	public function testCreateMapsNotPermittedTo403(): void {
		$this->labelService->method('create')->willThrowException(new NotPermittedException());

		$response = $this->controller->create(1, 'Urgent');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateReturnsLabel(): void {
		$label = $this->label();
		$this->labelService->method('update')
			->with(7, 'Renamed', null, 'alice')
			->willReturn($label);

		$response = $this->controller->update(7, 'Renamed');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($label, $response->getData());
	}

	public function testUpdateMapsInvalidColorTo400(): void {
		$this->labelService->method('update')
			->willThrowException(new InvalidInputException('Color must be a 6-digit hex value without "#"'));

		$response = $this->controller->update(7, null, 'red');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateMapsNotPermittedTo403(): void {
		$this->labelService->method('update')->willThrowException(new NotPermittedException());

		$response = $this->controller->update(7, 'Renamed');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdateMapsDoesNotExistTo404(): void {
		$this->labelService->method('update')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->update(7, 'Renamed');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDestroyReturnsEmptyBody(): void {
		$this->labelService->expects(self::once())->method('delete')->with(7, 'alice');

		$response = $this->controller->destroy(7);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());
	}

	public function testDestroyMapsNotPermittedTo403(): void {
		$this->labelService->method('delete')->willThrowException(new NotPermittedException());

		$response = $this->controller->destroy(7);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDestroyMapsDoesNotExistTo404(): void {
		$this->labelService->method('delete')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->destroy(7);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}

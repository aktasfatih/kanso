<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\ArchiveRuleController;
use OCA\Kanso\Db\ArchiveRule;
use OCA\Kanso\Service\ArchiveService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ArchiveRuleControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private ArchiveService&MockObject $archiveService;
	private ArchiveRuleController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->archiveService = $this->createMock(ArchiveService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new ArchiveRuleController(
			'kanso',
			$this->request,
			$userSession,
			$this->archiveService,
		);
	}

	private function rule(int $id = 3): ArchiveRule {
		$rule = new ArchiveRule();
		$rule->setId($id);
		$rule->setBoardId(1);
		$rule->setStackId(null);
		$rule->setCondition(ArchiveRule::CONDITION_DONE_FOR);
		$rule->setThresholdSeconds(86400);
		$rule->setEnabled(true);
		$rule->setCreatedAt(0);
		return $rule;
	}

	public function testIndexReturnsRules(): void {
		$this->archiveService->method('listForBoard')->with(1, 'alice')->willReturn([$this->rule()]);

		$response = $this->controller->index(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $response->getData());
	}

	public function testIndexMapsNotPermittedTo403(): void {
		$this->archiveService->method('listForBoard')->willThrowException(new NotPermittedException());

		$response = $this->controller->index(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testCreateReturnsRule(): void {
		$rule = $this->rule();
		$this->archiveService->method('create')
			->with(1, null, ArchiveRule::CONDITION_DONE_FOR, 86400, 'alice')
			->willReturn($rule);

		$response = $this->controller->create(1, null, ArchiveRule::CONDITION_DONE_FOR, 86400);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($rule, $response->getData());
	}

	public function testCreateMapsInvalidInputTo400(): void {
		$this->archiveService->method('create')->willThrowException(new InvalidInputException('bad'));

		$response = $this->controller->create(1, null, 99, 86400);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateMapsNotPermittedTo403(): void {
		$this->archiveService->method('create')->willThrowException(new NotPermittedException());

		$response = $this->controller->create(1, null, ArchiveRule::CONDITION_DONE_FOR, 86400);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdatePassesStackIdProvidedTrueWhenKeyPresent(): void {
		$this->request->method('getParam')->with('stackId', '__absent__')->willReturn(null);
		$this->archiveService->expects(self::once())
			->method('update')
			->with(3, null, true, null, 3600, null, 'alice')
			->willReturn($this->rule());

		$response = $this->controller->update(3, null, null, 3600, null);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdatePassesStackIdProvidedFalseWhenKeyAbsent(): void {
		$this->request->method('getParam')->with('stackId', '__absent__')->willReturn('__absent__');
		$this->archiveService->expects(self::once())
			->method('update')
			->with(3, null, false, null, 3600, null, 'alice')
			->willReturn($this->rule());

		$response = $this->controller->update(3, null, null, 3600, null);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateMapsNotFoundTo404(): void {
		$this->request->method('getParam')->willReturn('__absent__');
		$this->archiveService->method('update')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->update(3, null, null, 3600, null);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDestroyReturnsRule(): void {
		$rule = $this->rule();
		$this->archiveService->method('delete')->with(3, 'alice')->willReturn($rule);

		$response = $this->controller->destroy(3);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($rule, $response->getData());
	}

	public function testDestroyMapsNotPermittedTo403(): void {
		$this->archiveService->method('delete')->willThrowException(new NotPermittedException());

		$response = $this->controller->destroy(3);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testArchiveNowReturnsCount(): void {
		$this->archiveService->method('archiveNow')->with(3, 'alice')->willReturn(4);

		$response = $this->controller->archiveNow(3);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['archived' => 4], $response->getData());
	}

	public function testArchiveNowMapsNotPermittedTo403(): void {
		$this->archiveService->method('archiveNow')->willThrowException(new NotPermittedException());

		$response = $this->controller->archiveNow(3);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}

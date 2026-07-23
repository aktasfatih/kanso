<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\RecurRuleController;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\RecurrenceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RecurRuleControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private RecurrenceService&MockObject $recurrenceService;
	private RecurRuleController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->recurrenceService = $this->createMock(RecurrenceService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new RecurRuleController(
			'kanso',
			$this->request,
			$userSession,
			$this->recurrenceService,
		);
	}

	private function rule(int $id = 3): RecurRule {
		$rule = new RecurRule();
		$rule->setId($id);
		$rule->setBoardId(1);
		$rule->setTemplateCardId(10);
		$rule->setTargetStackId(5);
		$rule->setMode(RecurRule::MODE_CLONE);
		$rule->setRrule('FREQ=DAILY');
		$rule->setDuedatePolicy(RecurRule::POLICY_AT_OCCURRENCE);
		$rule->setDuedateOffsetSeconds(0);
		$rule->setSkipWhileOpen(false);
		$rule->setEnabled(true);
		$rule->setOwner('alice');
		$rule->setLastSpawnedAt(0);
		$rule->setNextOccurrenceAt(1_800_000_000);
		$rule->setOccurrencesSpawned(0);
		$rule->setCreatedAt(0);
		return $rule;
	}

	public function testIndexReturnsRules(): void {
		$this->recurrenceService->method('listForBoard')->with(1, 'alice')->willReturn([$this->rule()]);

		$response = $this->controller->index(1);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $response->getData());
	}

	public function testIndexMapsNotPermittedTo403(): void {
		$this->recurrenceService->method('listForBoard')->willThrowException(new NotPermittedException());

		$response = $this->controller->index(1);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testCreateReturnsRule(): void {
		$rule = $this->rule();
		$this->recurrenceService->method('create')
			->with(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', 0, 0, false, 'alice')
			->willReturn($rule);

		$response = $this->controller->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', 0, 0, false);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($rule, $response->getData());
	}

	public function testCreateMapsInvalidRruleTo400(): void {
		$this->recurrenceService->method('create')->willThrowException(new InvalidInputException('Invalid recurrence rule'));

		$response = $this->controller->create(1, 10, 5, RecurRule::MODE_CLONE, 'GARBAGE', 0, 0, false);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testCreateMapsNotPermittedTo403(): void {
		$this->recurrenceService->method('create')->willThrowException(new NotPermittedException());

		$response = $this->controller->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', 0, 0, false);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdateReturnsRule(): void {
		$this->recurrenceService->expects(self::once())
			->method('update')
			->with(3, null, null, null, 'FREQ=WEEKLY', null, null, null, false, 'alice')
			->willReturn($this->rule());

		$response = $this->controller->update(3, null, null, null, 'FREQ=WEEKLY', null, null, null, false);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateMapsNotFoundTo404(): void {
		$this->recurrenceService->method('update')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->update(3, null, null, null, 'FREQ=WEEKLY', null, null, null, null);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDestroyReturnsRule(): void {
		$rule = $this->rule();
		$this->recurrenceService->method('delete')->with(3, 'alice')->willReturn($rule);

		$response = $this->controller->destroy(3);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($rule, $response->getData());
	}

	public function testDestroyMapsNotPermittedTo403(): void {
		$this->recurrenceService->method('delete')->willThrowException(new NotPermittedException());

		$response = $this->controller->destroy(3);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCreateNowReturnsCard(): void {
		$card = new Card();
		$card->setId(99);
		$this->recurrenceService->method('createNow')->with(3, 'alice')->willReturn($card);

		$response = $this->controller->createNow(3);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['card' => $card], $response->getData());
	}

	public function testCreateNowMapsNotPermittedTo403(): void {
		$this->recurrenceService->method('createNow')->willThrowException(new NotPermittedException());

		$response = $this->controller->createNow(3);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCreateNowMapsBadRruleTo400(): void {
		$this->recurrenceService->method('createNow')->willThrowException(new InvalidInputException('Invalid recurrence rule'));

		$response = $this->controller->createNow(3);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}

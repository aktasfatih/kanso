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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ArchiveRuleControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private ArchiveService&MockObject $archiveService;
	private ArchiveRuleController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->archiveService = $this->createMock(ArchiveService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new ArchiveRuleController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->archiveService,
		);
	}

	/**
	 * PATCH /api/archive-rules/{id} the way a real client does: $body IS the
	 * request body, so a key it omits is genuinely absent rather than a defaulted
	 * null. That distinction is the whole contract of this endpoint - calling
	 * update() with positional nulls would prove nothing, because an omitted
	 * `stackId` and an explicit `stackId: null` look identical by then.
	 *
	 * @param array<string, mixed> $body
	 */
	private function patch(int $id, array $body): JSONResponse {
		$params = ['id' => $id] + $body;
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		// Faithful to the real Request::getParam(), which is isset()-based - so a
		// sent-but-null key reads back as the default. Stubbing it accurately is
		// what makes these tests able to fail if the presence check ever regresses
		// to that accessor; a bare mock would hand back null and pass either way.
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);
		$controller = new ArchiveRuleController('kanso', $request, $this->userSession, $this->archiveService);
		// The dispatcher casts a non-null value to the parameter's declared type
		// before the method sees it; mirror that so a junk `stackId` arrives the
		// way it really would.
		$stackId = ($body['stackId'] ?? null) === null ? null : (int)$body['stackId'];
		return $controller->update(
			$id,
			$stackId,
			$body['condition'] ?? null,
			$body['thresholdSeconds'] ?? null,
			$body['enabled'] ?? null,
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

	/**
	 * The re-scope-to-the-whole-board action the endpoint promises: an explicit
	 * `stackId: null` must reach the service as "provided", or a rule pinned to a
	 * column can never be widened again.
	 */
	public function testUpdateRescopesToWholeBoardOnExplicitNullStackId(): void {
		$this->archiveService->expects(self::once())
			->method('update')
			->with(3, null, true, null, 3600, null, 'alice')
			->willReturn($this->rule());

		$response = $this->patch(3, ['stackId' => null, 'thresholdSeconds' => 3600]);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdatePassesStackIdProvidedTrueWhenScopedToAStack(): void {
		$this->archiveService->expects(self::once())
			->method('update')
			->with(3, 7, true, null, 3600, null, 'alice')
			->willReturn($this->rule());

		$response = $this->patch(3, ['stackId' => 7, 'thresholdSeconds' => 3600]);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateLeavesScopeUntouchedWhenKeyAbsent(): void {
		$this->archiveService->expects(self::once())
			->method('update')
			->with(3, null, false, null, 3600, null, 'alice')
			->willReturn($this->rule());

		$response = $this->patch(3, ['thresholdSeconds' => 3600]);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * Presence is read off the raw body, so no magic value a client can put in
	 * `stackId` makes the key look absent. (The previous sentinel-default check
	 * treated a literal '__absent__' string as "not sent".)
	 */
	public function testUpdateTreatsLiteralSentinelStringAsAPresentKey(): void {
		$this->archiveService->expects(self::once())
			->method('update')
			->with(3, 0, true, null, null, null, 'alice')
			->willReturn($this->rule());

		$response = $this->patch(3, ['stackId' => '__absent__']);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * Re-scoping is a board mutation, so a user without MANAGE gets 403 - the
	 * service is the gate, and the controller must surface its refusal.
	 */
	public function testUpdateMapsNotPermittedTo403(): void {
		$this->archiveService->expects(self::once())
			->method('update')
			->with(3, null, true, null, null, null, 'alice')
			->willThrowException(new NotPermittedException());

		$response = $this->patch(3, ['stackId' => null]);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}

	public function testUpdateMapsNotFoundTo404(): void {
		$this->archiveService->method('update')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->patch(3, ['thresholdSeconds' => 3600]);
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

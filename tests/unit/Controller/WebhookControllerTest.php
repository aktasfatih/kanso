<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Controller;

use OCA\Kanso\Controller\WebhookController;
use OCA\Kanso\Service\ForgejoWebhookService;
use OCA\Kanso\Service\GithubWebhookService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NonJsonWebhookBodyException;
use OCA\Kanso\Service\NotPermittedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The PUBLIC webhook ingest endpoint's status mapping. The distinction that
 * matters: a bad signature is an attacker signal (401 + brute-force throttle),
 * while a correctly-signed body that is not JSON is a MISCONFIGURED user - the
 * webhook was created with GitHub's form-encoded content type - and must get a
 * 400 carrying the fix, with NO throttle.
 */
class WebhookControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private GithubWebhookService&MockObject $webhookService;
	private ForgejoWebhookService&MockObject $forgejoService;
	private WebhookController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturn('sha256=abc');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->webhookService = $this->createMock(GithubWebhookService::class);
		$this->forgejoService = $this->createMock(ForgejoWebhookService::class);
		$this->controller = new WebhookController(
			'kanso',
			$this->request,
			$this->userSession,
			$this->webhookService,
			$this->forgejoService,
		);
	}

	public function testFormEncodedBodyGets400WithTheContentTypeHint(): void {
		$this->webhookService->method('handleWebhook')
			->willThrowException(new NonJsonWebhookBodyException('Webhook body is not JSON'));

		$response = $this->controller->github(1);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertSame('invalid_payload', $data['error']);
		self::assertSame('Set the webhook Content type to application/json', $data['hint']);
	}

	public function testMisconfiguredContentTypeIsNotThrottled(): void {
		// A correctly-signed sender with the wrong content type is a user who
		// mis-created their webhook, not an attacker - throttling them would
		// punish the very deliveries the 400 is meant to help them fix.
		$this->webhookService->method('handleWebhook')
			->willThrowException(new NonJsonWebhookBodyException('Webhook body is not JSON'));

		self::assertFalse($this->controller->github(1)->isThrottled());
	}

	public function testARejectedCardMutationDoesNotGetTheContentTypeHint(): void {
		// handleWebhook() also creates and moves cards (issue intake, PR moves),
		// and those throw the plain InvalidInputException. Their content type was
		// already application/json, so telling them to change it is a confidently
		// wrong diagnosis - and it is the only line the user sees in GitHub's
		// delivery log. Still a 400, but reported as what actually went wrong.
		$this->webhookService->method('handleWebhook')
			->willThrowException(new InvalidInputException('Card title must not be empty'));

		$response = $this->controller->github(1);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertArrayNotHasKey('hint', $data);
		self::assertSame('Card title must not be empty', $data['message']);
		self::assertFalse($response->isThrottled());
	}

	public function testInvalidSignatureIs401AndStillThrottled(): void {
		$this->webhookService->method('handleWebhook')
			->willThrowException(new NotPermittedException('Invalid webhook signature'));

		$response = $this->controller->github(1);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertTrue($response->isThrottled());
	}

	public function testAcceptedNoopStillReturns200(): void {
		// A ping (or any non-PR/issue event) is valid JSON and a legitimate no-op:
		// the 400 above must not have collaterally hardened this path.
		$this->webhookService->method('handleWebhook')->willReturn(['handled' => false]);

		$response = $this->controller->github(1);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['handled' => false], $response->getData());
		self::assertFalse($response->isThrottled());
	}

	public function testUnknownBoardIs404(): void {
		$this->webhookService->method('handleWebhook')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->github(999);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertFalse($response->isThrottled());
	}
}

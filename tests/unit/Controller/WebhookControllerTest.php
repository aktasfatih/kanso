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
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebhookControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private GithubWebhookService&MockObject $webhookService;
	private ForgejoWebhookService&MockObject $forgejoService;
	private WebhookController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
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

	/** @return array<string, array{string}> */
	public static function providers(): array {
		return [
			'GitHub' => ['github'],
			'Forgejo' => ['forgejo'],
		];
	}

	#[DataProvider('providers')]
	public function testIngestRemainsPublicWithoutPreVerificationLockout(string $provider): void {
		$method = new \ReflectionMethod(WebhookController::class, $provider);

		self::assertSame([], $method->getAttributes(BruteForceProtection::class));
		self::assertCount(1, $method->getAttributes(PublicPage::class));
		self::assertCount(1, $method->getAttributes(NoCSRFRequired::class));
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
		$this->webhookService->method('handleWebhook')
			->willThrowException(new NonJsonWebhookBodyException('Webhook body is not JSON'));

		self::assertFalse($this->controller->github(1)->isThrottled());
	}

	public function testARejectedCardMutationDoesNotGetTheContentTypeHint(): void {
		$this->webhookService->method('handleWebhook')
			->willThrowException(new InvalidInputException('Card title must not be empty'));

		$response = $this->controller->github(1);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertArrayNotHasKey('hint', $data);
		self::assertSame('Card title must not be empty', $data['message']);
		self::assertFalse($response->isThrottled());
	}

	#[DataProvider('providers')]
	public function testInvalidSignatureIs401WithoutSharedThrottling(string $provider): void {
		$service = $provider === 'github' ? $this->webhookService : $this->forgejoService;
		$service->method('handleWebhook')
			->willThrowException(new NotPermittedException('Invalid webhook signature'));

		$response = $this->controller->$provider(1);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'unauthorized'], $response->getData());
		self::assertFalse($response->isThrottled());
	}

	#[DataProvider('providers')]
	public function testAcceptedDeliveryReturnsItsResultWithoutThrottling(string $provider): void {
		$service = $provider === 'github' ? $this->webhookService : $this->forgejoService;
		$result = ['handled' => true, 'cardId' => 42, 'moved' => true];
		$service->method('handleWebhook')->willReturn($result);

		$response = $this->controller->$provider(1);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($result, $response->getData());
		self::assertFalse($response->isThrottled());
	}

	#[DataProvider('providers')]
	public function testAcceptedNoopStillReturns200(string $provider): void {
		$service = $provider === 'github' ? $this->webhookService : $this->forgejoService;
		$service->method('handleWebhook')->willReturn(['handled' => false]);

		$response = $this->controller->$provider(1);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['handled' => false], $response->getData());
		self::assertFalse($response->isThrottled());
	}

	#[DataProvider('providers')]
	public function testUnknownBoardIs404(string $provider): void {
		$service = $provider === 'github' ? $this->webhookService : $this->forgejoService;
		$service->method('handleWebhook')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->$provider(999);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'not_found'], $response->getData());
		self::assertFalse($response->isThrottled());
	}

	#[DataProvider('providers')]
	public function testMissingSignatureIsStillVerifiedAndRejected(string $provider): void {
		$service = $provider === 'github' ? $this->webhookService : $this->forgejoService;
		$service->expects(self::once())->method('handleWebhook')->with(1, '', '')
			->willThrowException(new NotPermittedException('Invalid webhook signature'));

		$response = $this->controller->$provider(1);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'unauthorized'], $response->getData());
		self::assertFalse($response->isThrottled());
	}

	/** @return array<string, array{string, array<string, string>, string}> */
	public static function signatureHeaders(): array {
		return [
			'GitHub' => ['github', ['X-Hub-Signature-256' => 'sha256=github'], 'sha256=github'],
			'Forgejo first' => ['forgejo', [
				'X-Forgejo-Signature' => 'forgejo',
				'X-Gitea-Signature' => 'gitea',
				'X-Hub-Signature-256' => 'sha256=github',
			], 'forgejo'],
			'Gitea fallback' => ['forgejo', ['X-Gitea-Signature' => 'gitea'], 'gitea'],
			'GitHub fallback' => ['forgejo', ['X-Hub-Signature-256' => 'sha256=github'], 'sha256=github'],
		];
	}

	/** @param array<string, string> $headers */
	#[DataProvider('signatureHeaders')]
	public function testSignatureHeadersReachOnlyTheMatchingProvider(string $provider, array $headers, string $signature): void {
		$this->request->method('getHeader')->willReturnCallback(static fn (string $header): string => $headers[$header] ?? '');
		$service = $provider === 'github' ? $this->webhookService : $this->forgejoService;
		$otherService = $provider === 'github' ? $this->forgejoService : $this->webhookService;
		$service->expects(self::once())->method('handleWebhook')->with(1, $signature, '')
			->willReturn(['handled' => false]);
		$otherService->expects(self::never())->method('handleWebhook');

		self::assertSame(Http::STATUS_OK, $this->controller->$provider(1)->getStatus());
	}
}

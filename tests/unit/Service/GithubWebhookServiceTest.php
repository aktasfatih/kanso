<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\CardLinkService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\GithubWebhookService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GithubWebhookServiceTest extends TestCase {
	private const SECRET = 'topsecretkey';

	private BoardMapper&MockObject $boardMapper;
	private StackMapper&MockObject $stackMapper;
	private CardService&MockObject $cardService;
	private CardLinkService&MockObject $cardLinkService;
	private PermissionService&MockObject $permissionService;
	private ISecureRandom&MockObject $secureRandom;
	private IURLGenerator&MockObject $urlGenerator;
	private GithubWebhookService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->cardLinkService = $this->createMock(CardLinkService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = new GithubWebhookService(
			$this->boardMapper,
			$this->stackMapper,
			$this->cardService,
			$this->cardLinkService,
			$this->permissionService,
			$this->secureRandom,
			$this->urlGenerator,
		);
	}

	private function board(int $id = 1, ?string $secret = self::SECRET): Board {
		$b = new Board();
		$b->setId($id);
		$b->setOwner('alice');
		$b->setDeletedAt(0);
		$b->setWebhookSecret($secret);
		return $b;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$c = new Card();
		$c->setId($id);
		$c->setBoardId($boardId);
		$c->setDeletedAt(0);
		return $c;
	}

	private function stack(int $id, int $role): Stack {
		$s = new Stack();
		$s->setId($id);
		$s->setRole($role);
		return $s;
	}

	private function sign(string $body): string {
		return 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
	}

	private function prBody(string $action, string $branch, bool $merged = false): string {
		return json_encode([
			'action' => $action,
			'pull_request' => [
				'head' => ['ref' => $branch],
				'html_url' => 'https://github.com/octo/app/pull/3',
				'merged' => $merged,
			],
		]);
	}

	// ---- signature verification ------------------------------------------

	public function testRejectsInvalidSignature(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = $this->prBody('opened', 'kanso-9-x');

		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, 'sha256=deadbeef', $body);
	}

	public function testRejectsMissingSignature(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());

		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, '', $this->prBody('opened', 'kanso-9-x'));
	}

	public function testRejectsWhenWebhookDisabled(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1, null));
		$body = $this->prBody('opened', 'kanso-9-x');

		$this->expectException(NotPermittedException::class);
		// Even a "correctly signed" body must fail when no secret is configured.
		$this->service->handleWebhook(1, $this->sign($body), $body);
	}

	// ---- no-ops -----------------------------------------------------------

	public function testPingEventIsAcceptedNoop(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = json_encode(['zen' => 'Keep it simple', 'hook_id' => 1]);
		$this->cardService->expects(self::never())->method('move');

		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertFalse($result['handled']);
	}

	public function testValidSignatureNonArrayBodyIsNoop(): void {
		// A correctly-signed body that is a bare JSON scalar must not 500 (#3477).
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = '"just a string"';
		$this->cardService->expects(self::never())->method('move');

		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testUnknownBranchIsNoop(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$body = $this->prBody('opened', 'feature/not-a-kanso-branch');
		$this->cardService->expects(self::never())->method('move');

		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	public function testCardOnAnotherBoardIsIgnored(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 2)); // board 2!
		$this->cardService->expects(self::never())->method('move');
		$body = $this->prBody('opened', 'kanso-9-x');

		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['handled']);
	}

	// ---- auto-move --------------------------------------------------------

	public function testMergedPrMovesCardToDoneStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(5, Stack::ROLE_DONE));
		$this->cardLinkService->expects(self::once())->method('addLink')
			->with(9, 'https://github.com/octo/app/pull/3', 'alice');
		$this->cardService->expects(self::once())->method('move')
			->with(9, 5, null, 'alice')->willReturn($this->card(9, 1));

		$body = $this->prBody('closed', 'kanso-9-fix', true);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);

		self::assertTrue($result['handled']);
		self::assertTrue($result['moved']);
		self::assertSame(9, $result['cardId']);
	}

	public function testOpenedPrMovesCardToReviewStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_REVIEW)
			->willReturn($this->stack(4, Stack::ROLE_REVIEW));
		$this->cardService->expects(self::once())->method('move')->with(9, 4, null, 'alice')
			->willReturn($this->card(9, 1));

		$body = $this->prBody('opened', 'kanso-9-x');
		self::assertTrue($this->service->handleWebhook(1, $this->sign($body), $body)['moved']);
	}

	public function testClosedUnmergedPrDoesNotMove(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('closed', 'kanso-9-x', false); // closed but not merged
		self::assertFalse($this->service->handleWebhook(1, $this->sign($body), $body)['moved']);
	}

	public function testNoTargetRoleStackDegradesToLinkOnly(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null); // no done-role stack
		$this->cardLinkService->expects(self::once())->method('addLink');
		$this->cardService->expects(self::never())->method('move');

		$body = $this->prBody('closed', 'kanso-9-x', true);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
	}

	public function testMoveFailureIsSwallowed(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardService->method('find')->with(9, 'alice')->willReturn($this->card(9, 1));
		$this->stackMapper->method('findByBoardAndRole')->willReturn($this->stack(5, Stack::ROLE_DONE));
		$this->cardService->method('move')->willThrowException(new NotPermittedException('review gate'));

		$body = $this->prBody('closed', 'kanso-9-x', true);
		$result = $this->service->handleWebhook(1, $this->sign($body), $body);
		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
	}

	// ---- config (MANAGE) --------------------------------------------------

	public function testRotateSecretRequiresManageAndReturnsSecret(): void {
		$board = $this->board(1, null);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->secureRandom->method('generate')->willReturn('newsecret123');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/webhook');
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertSame('newsecret123', $b->getWebhookSecret());
				return $b;
			});

		$result = $this->service->rotateSecret(1, 'alice');
		self::assertSame('newsecret123', $result['secret']);
		self::assertSame('https://nc/webhook', $result['payloadUrl']);
	}

	public function testRotateSecretDeniedWithoutManage(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->rotateSecret(1, 'mallory');
	}

	public function testGetConfigReportsEnabledState(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/webhook');

		$config = $this->service->getConfig(1, 'alice');
		self::assertTrue($config['enabled']);
		self::assertSame('https://nc/webhook', $config['payloadUrl']);
	}

	public function testDisableClearsSecret(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertNull($b->getWebhookSecret());
				return $b;
			});

		$this->service->disable(1, 'alice');
	}
}

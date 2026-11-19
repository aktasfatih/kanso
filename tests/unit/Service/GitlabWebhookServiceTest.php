<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\CardLinkService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\GitlabWebhookService;
use OCA\Kanso\Service\LabelService;
use OCA\Kanso\Service\NonJsonWebhookBodyException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GitlabWebhookServiceTest extends TestCase {
	private const SECRET = 'gitlabsecretkey';
	private const BASE = 'https://git.example.org/team/subgroup/app';

	private BoardMapper&MockObject $boardMapper;
	private StackMapper&MockObject $stackMapper;
	private CardService&MockObject $cardService;
	private CardMapper&MockObject $cardMapper;
	private CardLinkService&MockObject $cardLinkService;
	private CardLinkMapper&MockObject $cardLinkMapper;
	private PermissionService&MockObject $permissionService;
	private ISecureRandom&MockObject $secureRandom;
	private IURLGenerator&MockObject $urlGenerator;
	private LabelService&MockObject $labelService;
	private LabelMapper&MockObject $labelMapper;
	private GitlabWebhookService $service;
	private ?Card $currentCard = null;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->cardService->method('find')->willReturnCallback(fn (int $id): Card => $this->currentCard ?? $this->card($id));
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => $this->currentCard ?? $this->card($id));
		$this->cardLinkService = $this->createMock(CardLinkService::class);
		$this->cardLinkMapper = $this->createMock(CardLinkMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->labelService = $this->createMock(LabelService::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->service = new GitlabWebhookService(
			$this->boardMapper,
			$this->stackMapper,
			$this->cardService,
			$this->cardMapper,
			$this->cardLinkService,
			$this->cardLinkMapper,
			$this->permissionService,
			new CardVisibilityScope(),
			$this->secureRandom,
			$this->urlGenerator,
			$this->labelService,
			$this->labelMapper,
			new \OCA\Kanso\Tests\Stubs\CardMutationService(),
		);
	}

	private function board(int $id = 1, ?string $secret = self::SECRET): Board {
		$b = new Board();
		$b->setId($id);
		$b->setOwner('alice');
		$b->setDeletedAt(0);
		$b->setGitlabWebhookSecret($secret);
		return $b;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$c = new Card();
		$c->setId($id);
		$c->setBoardId($boardId);
		$c->setStackId(10);
		$c->setDeletedAt(0);
		return $c;
	}

	private function stack(int $id, int $role, int $boardId = 1, int $deletedAt = 0): Stack {
		$s = new Stack();
		$s->setId($id);
		$s->setRole($role);
		$s->setBoardId($boardId);
		$s->setDeletedAt($deletedAt);
		return $s;
	}

	private function body(string $kind, string $action, string $state, string $title = 'GitLab work', string $branch = 'kanso-9-work'): string {
		return json_encode([
			'object_kind' => $kind,
			'object_attributes' => [
				'action' => $action,
				'state' => $state,
				'title' => $title,
				'source_branch' => $branch,
				'url' => self::BASE . '/-/' . ($kind === 'merge_request' ? 'merge_requests' : 'issues') . '/3',
			],
		], JSON_THROW_ON_ERROR);
	}

	private function link(int $cardId = 9): CardLink {
		$link = new CardLink();
		$link->setId(20);
		$link->setCardId($cardId);
		$link->setProvider(CardLink::PROVIDER_GITLAB);
		$link->setUrl(self::BASE . '/-/issues/3');
		return $link;
	}

	/** @return array<string, array{string, string|null}> */
	public static function invalidTokens(): array {
		return [
			'missing' => ['', self::SECRET],
			'wrong' => ['other-secret', self::SECRET],
			'whitespace' => [' ' . self::SECRET, self::SECRET],
			'case' => [strtoupper(self::SECRET), self::SECRET],
			'empty secret' => [self::SECRET, null],
		];
	}

	#[DataProvider('invalidTokens')]
	public function testRejectsInvalidTokenBeforeParsingOrMutation(string $token, ?string $secret): void {
		$this->boardMapper->method('find')->willReturn($this->board(secret: $secret));
		$this->boardMapper->expects(self::never())->method('update');
		$this->cardLinkMapper->expects(self::never())->method('findByBoardAndUrls');
		$this->cardLinkMapper->expects(self::never())->method('update');
		$this->cardService->expects(self::never())->method('move');
		$this->cardLinkService->expects(self::never())->method('addLink');
		$this->expectException(NotPermittedException::class);
		$this->service->handleWebhook(1, $token, 'not json');
	}

	public function testValidTokenMalformedJsonIsRejected(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->expectException(NonJsonWebhookBodyException::class);
		$this->service->handleWebhook(1, self::SECRET, 'not json');
	}

	/** @return array<string, array{string}> */
	public static function ignoredBodies(): array {
		return [
			'ping' => ['{"object_kind":"push"}'],
			'scalar' => ['null'],
			'missing data' => ['{"object_kind":"issue"}'],
			'missing state' => ['{"object_kind":"merge_request","object_attributes":{"url":"https://git.example.org/team/app/-/merge_requests/3","action":"merge","source_branch":"kanso-9-work"}}'],
			'wrong data type' => ['{"object_kind":"issue","object_attributes":false}'],
			'invalid URL' => ['{"object_kind":"merge_request","object_attributes":{"url":"javascript:alert(1)","source_branch":"kanso-9-work","action":"merge"}}'],
			'wrong resource' => ['{"object_kind":"merge_request","object_attributes":{"url":"https://git.example.org/team/app/-/issues/3","source_branch":"kanso-9-work","action":"merge"}}'],
		];
	}

	#[DataProvider('ignoredBodies')]
	public function testUnsupportedOrMalformedEventsDoNotMutate(string $body): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->cardService->expects(self::never())->method('move');
		$this->cardLinkService->expects(self::never())->method('addLink');
		$this->cardLinkMapper->expects(self::never())->method('update');
		self::assertFalse($this->service->handleWebhook(1, self::SECRET, $body)['handled']);
	}

	/** @return array<string, array{string, string, int, string}> */
	public static function mergeRequestTransitions(): array {
		return [
			'opened' => ['open', 'opened', Stack::ROLE_REVIEW, CardLink::STATE_OPEN],
			'reopened' => ['reopen', 'opened', Stack::ROLE_REVIEW, CardLink::STATE_OPEN],
			'merged' => ['merge', 'merged', Stack::ROLE_DONE, CardLink::STATE_MERGED],
			'closed without merge' => ['close', 'closed', 0, CardLink::STATE_CLOSED],
			'updated' => ['update', 'opened', 0, CardLink::STATE_OPEN],
		];
	}

	#[DataProvider('mergeRequestTransitions')]
	public function testMergeRequestsNormalizeStatesAndReuseWorkflow(string $action, string $state, int $role, string $linkState): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$link = $this->link();
		$this->cardLinkService->expects(self::once())->method('addLink')->with(9, self::BASE . '/-/merge_requests/3', 'alice')->willReturn($link);
		$this->cardLinkService->expects(self::once())->method('updateFromWebhook')->with($link, $linkState, 'GitLab work');
		if ($role > 0) {
			$this->stackMapper->method('findByBoardAndRole')->with(1, $role)->willReturn($this->stack(33, $role));
			$this->cardService->expects(self::once())->method('move')->with(9, 33, null, 'alice');
		} else {
			$this->cardService->expects(self::never())->method('move');
		}
		$result = $this->service->handleWebhook(1, self::SECRET, $this->body('merge_request', $action, $state));
		self::assertTrue($result['handled']);
		self::assertSame($role > 0, $result['moved']);
	}

	/** @return array<string, array{string, string, int}> */
	public static function issueTransitions(): array {
		return [
			'closed' => ['close', 'closed', Stack::ROLE_DONE],
			'reopened' => ['reopen', 'opened', Stack::ROLE_IN_PROGRESS],
		];
	}

	#[DataProvider('issueTransitions')]
	public function testIssuesMatchNestedProjectUrlAndMoveLinkedCards(string $action, string $state, int $role): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$link = $this->link();
		$this->cardLinkMapper->expects(self::once())->method('findByBoardAndUrls')->with(1, [self::BASE . '/-/issues/3', self::BASE . '/-/issues/3/'])->willReturn([$link]);
		$this->cardLinkService->expects(self::once())->method('updateFromWebhook')->with($link, $state === 'opened' ? CardLink::STATE_OPEN : CardLink::STATE_CLOSED, 'GitLab work');
		$this->stackMapper->method('findByBoardAndRole')->with(1, $role)->willReturn($this->stack(33, $role));
		$this->cardService->expects(self::once())->method('move')->with(9, 33, null, 'alice');
		self::assertTrue($this->service->handleWebhook(1, self::SECRET, $this->body('issue', $action, $state))['moved']);
	}

	/** @return array<string, array{string}> */
	public static function repeatedDeliveries(): array {
		return ['merge request' => ['merge_request'], 'issue' => ['issue']];
	}

	#[DataProvider('repeatedDeliveries')]
	public function testRepeatedDeliveryDoesNotMoveOrReorderCardAgain(string $kind): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$card = $this->card();
		$this->currentCard = $card;
		$this->cardLinkService->method('addLink')->willReturn($this->link());
		$this->stackMapper->method('findByBoardAndRole')->willReturn($this->stack(33, Stack::ROLE_DONE));
		$this->cardService->expects(self::once())->method('move')->willReturnCallback(function () use ($card): Card {
			$card->setStackId(33);
			return $card;
		});
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link()]);
		$body = $this->body($kind, $kind === 'issue' ? 'close' : 'merge', $kind === 'issue' ? 'closed' : 'merged');
		self::assertTrue($this->service->handleWebhook(1, self::SECRET, $body)['moved']);
		self::assertFalse($this->service->handleWebhook(1, self::SECRET, $body)['moved']);
	}

	public function testCrossBoardBranchCannotMoveCard(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->currentCard = $this->card(9, 2);
		$this->cardService->expects(self::never())->method('move');
		$this->cardLinkService->expects(self::never())->method('addLink');
		self::assertFalse($this->service->handleWebhook(1, self::SECRET, $this->body('merge_request', 'merge', 'merged'))['handled']);
	}

	public function testReviewGateFailureDoesNotRejectDelivery(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->cardLinkService->method('addLink')->willReturn($this->link());
		$this->stackMapper->method('findByBoardAndRole')->willReturn($this->stack(33, Stack::ROLE_DONE));
		$this->cardService->method('move')->willThrowException(new NotPermittedException('Reviews pending'));
		$result = $this->service->handleWebhook(1, self::SECRET, $this->body('merge_request', 'merge', 'merged'));
		self::assertTrue($result['handled']);
		self::assertFalse($result['moved']);
	}

	public function testPrivateLinkedCardIdIsNotDisclosedToGitlab(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$card = $this->card();
		$card->setVisibility('private');
		$this->currentCard = $card;
		$this->cardLinkMapper->method('findByBoardAndUrls')->willReturn([$this->link()]);
		$result = $this->service->handleWebhook(1, self::SECRET, $this->body('issue', 'update', 'opened'));
		self::assertTrue($result['handled']);
		self::assertSame(0, $result['cardId']);
	}

	public function testUnlinkedIssueIsNotImported(): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->cardService->expects(self::never())->method('create');
		self::assertFalse($this->service->handleWebhook(1, self::SECRET, $this->body('issue', 'open', 'opened'))['handled']);
	}

	/** @return array<string, array{string}> */
	public static function configMethods(): array {
		return ['read' => ['getConfig'], 'rotate' => ['rotateSecret'], 'disable' => ['disable']];
	}

	#[DataProvider('configMethods')]
	public function testConfigurationRequiresManage(string $method): void {
		$this->boardMapper->method('find')->willReturn($this->board());
		$this->permissionService->expects(self::once())->method('assertPermission')->with(self::isInstanceOf(Board::class), 'bob', PermissionService::PERMISSION_MANAGE)->willThrowException(new NotPermittedException('denied'));
		$this->boardMapper->expects(self::never())->method('update');
		$this->expectException(NotPermittedException::class);
		$this->service->$method(1, 'bob');
	}

	public function testConfigNeverReturnsSecretAndRotationKeepsOtherProviders(): void {
		$board = $this->board();
		$board->setWebhookSecret('github-secret');
		$board->setForgejoWebhookSecret('forgejo-secret');
		$this->boardMapper->method('find')->willReturn($board);
		$this->secureRandom->method('generate')->willReturn('new-gitlab-secret');
		$this->urlGenerator->method('linkToRouteAbsolute')->with('kanso.webhook.gitlab', ['id' => 1])->willReturn('https://cloud.test/gitlab-webhook');
		self::assertArrayNotHasKey('secret', $this->service->getConfig(1, 'alice'));
		self::assertSame('new-gitlab-secret', $this->service->rotateSecret(1, 'alice')['secret']);
		self::assertSame('new-gitlab-secret', $board->getGitlabWebhookSecret());
		self::assertSame('github-secret', $board->getWebhookSecret());
		self::assertSame('forgejo-secret', $board->getForgejoWebhookSecret());
		self::assertArrayNotHasKey('gitlabWebhookSecret', $board->jsonSerialize());
		$this->service->disable(1, 'alice');
		self::assertNull($board->getGitlabWebhookSecret());
	}
}

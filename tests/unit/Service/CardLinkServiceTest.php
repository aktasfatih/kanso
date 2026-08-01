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
use OCA\Kanso\Service\CardLinkService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardLinkServiceTest extends TestCase {
	private CardLinkMapper&MockObject $cardLinkMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private ChangeNotifier&MockObject $changeNotifier;
	private IClientService&MockObject $clientService;
	private IClient&MockObject $client;
	private CardLinkService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardLinkMapper = $this->createMock(CardLinkMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($this->client);
		$this->service = new CardLinkService(
			$this->cardLinkMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->changeNotifier,
			$this->clientService,
		);
	}

	private function board(int $id = 1): Board {
		$b = new Board();
		$b->setId($id);
		$b->setDeletedAt(0);
		return $b;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$c = new Card();
		$c->setId($id);
		$c->setBoardId($boardId);
		$c->setDeletedAt(0);
		return $c;
	}

	private function expectCardLoaded(): Board {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	/** @var array<string, mixed>|null Options passed to the last client GET (for assertions). */
	private ?array $lastGetOptions = null;

	private function githubResponse(string $json): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($json);
		$this->client->method('get')->willReturnCallback(function (string $url, array $options = []) use ($response): IResponse {
			$this->lastGetOptions = $options;
			return $response;
		});
	}

	// ---- URL parsing ------------------------------------------------------

	public function testParsePullUrl(): void {
		[$kind, $owner, $repo, $number] = $this->service->parseGitHubUrl('https://github.com/octo/app/pull/42');
		self::assertSame(CardLink::KIND_PR, $kind);
		self::assertSame('octo', $owner);
		self::assertSame('app', $repo);
		self::assertSame(42, $number);
	}

	public function testParseIssueUrl(): void {
		[$kind, , , $number] = $this->service->parseGitHubUrl('https://github.com/octo/app/issues/7');
		self::assertSame(CardLink::KIND_ISSUE, $kind);
		self::assertSame(7, $number);
	}

	public function testParseOtherGithubUrlIsKindOther(): void {
		[$kind, , , $number] = $this->service->parseGitHubUrl('https://github.com/octo/app');
		self::assertSame(CardLink::KIND_OTHER, $kind);
		self::assertSame(0, $number);
	}

	public function testParseRejectsNonGithubUrl(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->parseGitHubUrl('https://evil.example.com/octo/app/pull/1');
	}

	public function testParseRejectsHttp(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->parseGitHubUrl('http://github.com/octo/app/pull/1');
	}

	// ---- branch name ------------------------------------------------------

	public function testBranchNameSlugifies(): void {
		self::assertSame('kanso-9-fix-the-login-bug', CardLinkService::branchName(9, 'Fix the login bug!'));
		self::assertSame('kanso-9', CardLinkService::branchName(9, '  --- '));
	}

	// ---- addLink ----------------------------------------------------------

	public function testAddLinkRequiresEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardLinkMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->addLink(9, 'https://github.com/octo/app/pull/1', 'stranger');
	}

	public function testAddLinkRejectsNonGithubUrlBeforeInsert(): void {
		$this->expectCardLoaded();
		$this->cardLinkMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->addLink(9, 'ftp://example.com/x', 'bob');
	}

	public function testAddLinkInsertsPollsStateAndNotifies(): void {
		$this->expectCardLoaded();
		$this->cardLinkMapper->method('insert')->willReturnCallback(fn (CardLink $l): CardLink => $l);
		$this->githubResponse('{"title":"Fix login","state":"closed","merged_at":"2026-07-01T00:00:00Z"}');
		$this->cardLinkMapper->expects(self::once())->method('update');
		$this->changeNotifier->expects(self::once())->method('notify');

		$link = $this->service->addLink(9, 'https://github.com/octo/app/pull/42', 'bob');

		self::assertSame(CardLink::KIND_PR, $link->getKind());
		self::assertSame(CardLink::STATE_MERGED, $link->getState());
		self::assertSame('Fix login', $link->getTitle());
	}

	public function testAddLinkOpenPrState(): void {
		$this->expectCardLoaded();
		$this->cardLinkMapper->method('insert')->willReturnCallback(fn (CardLink $l): CardLink => $l);
		$this->githubResponse('{"title":"WIP","state":"open","merged_at":null}');

		$link = $this->service->addLink(9, 'https://github.com/octo/app/pull/5', 'bob');
		self::assertSame(CardLink::STATE_OPEN, $link->getState());
	}

	public function testAddLinkNetworkFailureLeavesStateUnknown(): void {
		$this->expectCardLoaded();
		$this->cardLinkMapper->method('insert')->willReturnCallback(fn (CardLink $l): CardLink => $l);
		$this->client->method('get')->willThrowException(new \RuntimeException('offline'));

		$link = $this->service->addLink(9, 'https://github.com/octo/app/pull/5', 'bob');
		self::assertSame(CardLink::STATE_UNKNOWN, $link->getState());
	}

	public function testAddOtherGithubLinkSkipsPoll(): void {
		$this->expectCardLoaded();
		$this->cardLinkMapper->method('insert')->willReturnCallback(fn (CardLink $l): CardLink => $l);
		$this->client->expects(self::never())->method('get');

		$link = $this->service->addLink(9, 'https://github.com/octo/app', 'bob');
		self::assertSame(CardLink::KIND_OTHER, $link->getKind());
		self::assertSame(CardLink::STATE_UNKNOWN, $link->getState());
	}

	public function testPollDisablesRedirects(): void {
		$this->expectCardLoaded();
		$this->cardLinkMapper->method('insert')->willReturnCallback(fn (CardLink $l): CardLink => $l);
		$this->githubResponse('{"title":"WIP","state":"open","merged_at":null}');

		$this->service->addLink(9, 'https://github.com/octo/app/pull/5', 'bob');

		self::assertNotNull($this->lastGetOptions, 'GitHub poll should have issued a GET');
		self::assertArrayHasKey('allow_redirects', $this->lastGetOptions);
		// Accept either the boolean-false or the max=0 form; both pin the host.
		$redirects = $this->lastGetOptions['allow_redirects'];
		self::assertTrue(
			$redirects === false || (is_array($redirects) && ($redirects['max'] ?? null) === 0),
			'GitHub poll must not follow redirects (SSRF defence in depth)',
		);
	}

	// ---- listForCard ------------------------------------------------------

	public function testListForCardRequiresRead(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_READ)
			->willThrowException(new NotPermittedException());

		$this->expectException(NotPermittedException::class);
		$this->service->listForCard(9, 'stranger');
	}

	public function testListForCardRefreshesStaleLinks(): void {
		$this->expectCardLoaded();
		$stale = new CardLink();
		$stale->setId(1);
		$stale->setCardId(9);
		$stale->setUrl('https://github.com/octo/app/pull/3');
		$stale->setKind(CardLink::KIND_PR);
		$stale->setState(CardLink::STATE_OPEN);
		$stale->setLastPolled(0);
		$this->cardLinkMapper->method('findByCard')->with(9)->willReturn([$stale]);
		$this->githubResponse('{"title":"Done","state":"closed","merged_at":"2026-07-02T00:00:00Z"}');
		$this->cardLinkMapper->expects(self::once())->method('update');

		$links = $this->service->listForCard(9, 'bob');
		self::assertSame(CardLink::STATE_MERGED, $links[0]->getState());
	}

	public function testListForCardDoesNotRepollFreshLinks(): void {
		$this->expectCardLoaded();
		$fresh = new CardLink();
		$fresh->setId(1);
		$fresh->setCardId(9);
		$fresh->setUrl('https://github.com/octo/app/pull/3');
		$fresh->setKind(CardLink::KIND_PR);
		$fresh->setState(CardLink::STATE_OPEN);
		$fresh->setLastPolled(time());
		$this->cardLinkMapper->method('findByCard')->with(9)->willReturn([$fresh]);
		$this->client->expects(self::never())->method('get');
		$this->cardLinkMapper->expects(self::never())->method('update');

		$this->service->listForCard(9, 'bob');
	}

	// ---- deleteLink -------------------------------------------------------

	public function testDeleteLinkRejectsCrossCardLink(): void {
		$this->expectCardLoaded();
		$other = new CardLink();
		$other->setId(5);
		$other->setCardId(99);
		$this->cardLinkMapper->method('find')->with(5)->willReturn($other);
		$this->cardLinkMapper->expects(self::never())->method('delete');

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service->deleteLink(9, 5, 'bob');
	}

	public function testDeleteLinkRemovesAndNotifies(): void {
		$this->expectCardLoaded();
		$link = new CardLink();
		$link->setId(5);
		$link->setCardId(9);
		$this->cardLinkMapper->method('find')->with(5)->willReturn($link);
		$this->cardLinkMapper->expects(self::once())->method('delete')->with($link);
		$this->changeNotifier->expects(self::once())->method('notify');

		$this->service->deleteLink(9, 5, 'bob');
	}
}

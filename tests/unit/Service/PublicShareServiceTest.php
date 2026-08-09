<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\PublicShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PublicShareServiceTest extends TestCase {
	private const TOKEN = 'a-very-long-unguessable-token-value-0123456789';

	private BoardMapper&MockObject $boardMapper;
	private StackMapper&MockObject $stackMapper;
	private CardMapper&MockObject $cardMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private ChecklistItemMapper&MockObject $checklistItemMapper;
	private LabelMapper&MockObject $labelMapper;
	private PermissionService&MockObject $permissionService;
	private ISecureRandom&MockObject $secureRandom;
	private IURLGenerator&MockObject $urlGenerator;
	private PublicShareService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = new PublicShareService(
			$this->boardMapper,
			$this->stackMapper,
			$this->cardMapper,
			$this->cardLabelMapper,
			$this->checklistItemMapper,
			$this->labelMapper,
			$this->permissionService,
			$this->secureRandom,
			$this->urlGenerator,
		);
	}

	private function board(int $id = 1, ?string $token = self::TOKEN, ?int $expiresAt = null): Board {
		$b = new Board();
		$b->setId($id);
		$b->setOwner('alice');
		$b->setTitle('Roadmap');
		$b->setColor('0082c9');
		$b->setDeletedAt(0);
		$b->setPublicShareToken($token);
		$b->setPublicShareExpiresAt($expiresAt);
		return $b;
	}

	private function stack(int $id, string $title, bool $archived = false): Stack {
		$s = new Stack();
		$s->setId($id);
		$s->setBoardId(1);
		$s->setTitle($title);
		$s->setArchived($archived);
		return $s;
	}

	private function card(int $id, int $stackId, string $title, bool $archived = false): Card {
		$c = new Card();
		$c->setId($id);
		$c->setBoardId(1);
		$c->setStackId($stackId);
		$c->setTitle($title);
		$c->setDescription('desc of ' . $id);
		$c->setArchived($archived);
		$c->setPriority(0);
		$c->setBoardSeq($id);
		return $c;
	}

	private function label(int $id, string $title, string $color): Label {
		$l = new Label();
		$l->setId($id);
		$l->setBoardId(1);
		$l->setTitle($title);
		$l->setColor($color);
		return $l;
	}

	// ── config (MANAGE) ────────────────────────────────────────────────────

	public function testEnableMintsTokenRequiresManageAndReturnsUrl(): void {
		$board = $this->board(1, null);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->secureRandom->expects(self::once())->method('generate')
			->with(64, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('freshtoken');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/p/freshtoken');
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertSame('freshtoken', $b->getPublicShareToken());
				return $b;
			});

		$result = $this->service->enable(1, 'alice');
		self::assertTrue($result['enabled']);
		self::assertSame('freshtoken', $result['token']);
		self::assertSame('https://nc/p/freshtoken', $result['url']);
	}

	public function testEnableDeniedWithoutManage(): void {
		$board = $this->board(1, null);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->enable(1, 'mallory');
	}

	public function testRotateReplacesTokenSoOldOneStopsWorking(): void {
		$board = $this->board(1, 'oldtoken');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->secureRandom->method('generate')->willReturn('rotatedtoken');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/p/rotatedtoken');
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertSame('rotatedtoken', $b->getPublicShareToken());
				return $b;
			});

		$result = $this->service->enable(1, 'alice');
		self::assertSame('rotatedtoken', $result['token']);
	}

	public function testDisableClearsToken(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertNull($b->getPublicShareToken());
				return $b;
			});

		$this->service->disable(1, 'alice');
	}

	public function testDisableDeniedWithoutManage(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->disable(1, 'mallory');
	}

	public function testGetConfigReportsEnabledAndUrl(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/p/tok');

		$config = $this->service->getConfig(1, 'alice');
		self::assertTrue($config['enabled']);
		self::assertSame(self::TOKEN, $config['token']);
		self::assertSame('https://nc/p/tok', $config['url']);
	}

	// ── public read (unauthenticated) ──────────────────────────────────────

	private function primePublicBoard(?int $expiresAt = null): void {
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN, $expiresAt));
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([
			$this->stack(10, 'To do'),
			$this->stack(11, 'Archived col', true),
		]);
		$this->cardMapper->method('findPublicByBoard')->with(1)->willReturn([
			$this->card(100, 10, 'Live card'),
			$this->card(101, 10, 'Archived card', true),
			$this->card(102, 11, 'Card in archived stack'),
		]);
		$this->labelMapper->method('findByBoard')->with(1)->willReturn([
			$this->label(5, 'Bug', 'ff0000'),
		]);
		$this->cardLabelMapper->method('findLabelIdsByBoard')->with(1)->willReturn([
			100 => [5],
		]);
		$this->checklistItemMapper->method('progressByBoardPublicOnly')->with(1)->willReturn([
			100 => ['total' => 3, 'done' => 1],
		]);
	}

	public function testGetPublicBoardReturnsStrippedPayload(): void {
		$this->primePublicBoard();
		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertSame('Roadmap', $payload['board']['title']);
		self::assertSame('0082c9', $payload['board']['color']);
		// Only the non-archived stack is present.
		self::assertCount(1, $payload['stacks']);
		self::assertSame(10, $payload['stacks'][0]['id']);
		// Only the live card in the live stack survives (archived card and the
		// card that lives in an archived stack are both dropped).
		self::assertCount(1, $payload['cards']);
		$card = $payload['cards'][0];
		self::assertSame('Live card', $card['title']);
		self::assertSame('desc of 100', $card['description']);
		self::assertSame([['name' => 'Bug', 'color' => 'ff0000']], $card['labels']);
		self::assertSame(['total' => 3, 'done' => 1], $card['checklist']);
		self::assertSame($payload['board']['prefix'] . '-100', $card['humanId']);
	}

	public function testPublicPayloadHasNoPeopleOrInternalFields(): void {
		$this->primePublicBoard();
		$payload = $this->service->getPublicBoard(self::TOKEN);

		// Board: no owner, no acl, no webhook secret, no share token.
		$boardKeys = array_keys($payload['board']);
		sort($boardKeys);
		self::assertSame(['color', 'prefix', 'title'], $boardKeys);

		// Card: exactly the whitelisted, people-free field set.
		$cardKeys = array_keys($payload['cards'][0]);
		sort($cardKeys);
		// `type` is a public-safe display attribute (renders as a tile icon like a
		// label/cover - no PII, no internal identifier), so it is a permitted key.
		self::assertSame(
			['allDay', 'checklist', 'description', 'duedate', 'humanId', 'id', 'labels', 'priority', 'stackId', 'status', 'title', 'type'],
			$cardKeys
		);

		// Explicitly assert the sensitive keys never appear.
		$json = json_encode($payload);
		foreach (['owner', 'assignee', 'comment', 'acl', 'webhook', 'subscriber', 'watcher', 'reviewState', 'activity'] as $forbidden) {
			self::assertStringNotContainsStringIgnoringCase($forbidden, $json, "public payload leaked '$forbidden'");
		}
	}

	public function testAssertTokenValidPassesForLiveToken(): void {
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN));
		// No exception = valid. It must NOT build the payload (no stack/card reads).
		$this->stackMapper->expects(self::never())->method('findByBoard');
		$this->cardMapper->expects(self::never())->method('findPublicByBoard');
		$this->service->assertTokenValid(self::TOKEN);
		$this->addToAssertionCount(1);
	}

	public function testAssertTokenValidRejectsExpired(): void {
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN, time() - 5));
		$this->expectException(DoesNotExistException::class);
		$this->service->assertTokenValid(self::TOKEN);
	}

	public function testUnknownTokenIs404(): void {
		$this->boardMapper->method('findByPublicToken')
			->willThrowException(new DoesNotExistException('no such token'));

		$this->expectException(DoesNotExistException::class);
		$this->service->getPublicBoard('does-not-exist');
	}

	public function testDisabledTokenIs404(): void {
		// A board whose token was concurrently cleared must not be served even if
		// somehow returned by the lookup (defence in depth).
		$this->boardMapper->method('findByPublicToken')->willReturn($this->board(1, null));

		$this->expectException(DoesNotExistException::class);
		$this->service->getPublicBoard(self::TOKEN);
	}

	public function testExpiredTokenIs404(): void {
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN, time() - 10));

		$this->expectException(DoesNotExistException::class);
		$this->service->getPublicBoard(self::TOKEN);
	}

	public function testFutureExpiryStillResolves(): void {
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN, time() + 3600));
		$this->stackMapper->method('findByBoard')->willReturn([]);
		$this->cardMapper->method('findPublicByBoard')->willReturn([]);
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardLabelMapper->method('findLabelIdsByBoard')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoardPublicOnly')->willReturn([]);

		$payload = $this->service->getPublicBoard(self::TOKEN);
		self::assertSame('Roadmap', $payload['board']['title']);
	}
}

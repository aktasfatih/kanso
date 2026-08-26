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
use OCA\Kanso\Db\Comment;
use OCA\Kanso\Db\CommentMapper;
use OCA\Kanso\Db\Label;
use OCA\Kanso\Db\LabelMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\PublicShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
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
	private CommentMapper&MockObject $commentMapper;
	private PermissionService&MockObject $permissionService;
	private ISecureRandom&MockObject $secureRandom;
	private IURLGenerator&MockObject $urlGenerator;
	private IUserManager&MockObject $userManager;
	private IL10N&MockObject $l10n;
	private PublicShareService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->checklistItemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->labelMapper = $this->createMock(LabelMapper::class);
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		// Translate returns the source string verbatim (the mock just echoes it).
		$this->l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->service = new PublicShareService(
			$this->boardMapper,
			$this->stackMapper,
			$this->cardMapper,
			$this->cardLabelMapper,
			$this->checklistItemMapper,
			$this->labelMapper,
			$this->commentMapper,
			$this->permissionService,
			$this->secureRandom,
			$this->urlGenerator,
			$this->userManager,
			$this->l10n,
		);
	}

	private function board(int $id = 1, ?string $token = self::TOKEN, ?int $expiresAt = null, bool $comments = false): Board {
		$b = new Board();
		$b->setId($id);
		$b->setOwner('alice');
		$b->setTitle('Roadmap');
		$b->setColor('0082c9');
		$b->setDeletedAt(0);
		$b->setPublicShareToken($token);
		$b->setPublicShareExpiresAt($expiresAt);
		$b->setPublicShareComments($comments);
		return $b;
	}

	private function comment(int $id, int $cardId, string $author, string $body, ?int $parentCommentId = null): Comment {
		$c = new Comment();
		$c->setId($id);
		$c->setCardId($cardId);
		$c->setParentCommentId($parentCommentId);
		$c->setAuthor($author);
		$c->setBody($body);
		$c->setCreatedAt(1000 + $id);
		$c->setEditedAt(0);
		$c->setDeletedAt(0);
		return $c;
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
		$this->cardLabelMapper->method('findLabelIdsByBoardPublicOnly')->with(1)->willReturn([
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
		// The anonymous snapshot must not even COMPUTE the waiting-on-client
		// aggregate (#3746) - it is excluded, not merely dropped.
		$this->checklistItemMapper->expects(self::never())->method('waitingByBoard');
		$payload = $this->service->getPublicBoard(self::TOKEN);

		// Board: no owner, no acl, no webhook secret, no share token. The
		// `commentsEnabled` flag is a public-safe boolean gate (#3949) - it says
		// WHETHER comments are shown, never who; with the opt-in OFF here it is
		// false and no comment data is present. `cardFeatures` (#5894) is the same
		// shape: five booleans saying which built-in card sections this board
		// renders, so the public link honours the manager's switches. No PII, no
		// internal identifier, nothing about a person.
		$boardKeys = array_keys($payload['board']);
		sort($boardKeys);
		self::assertSame(['cardFeatures', 'color', 'commentsEnabled', 'prefix', 'title'], $boardKeys);
		self::assertFalse($payload['board']['commentsEnabled']);
		// A board that never touched the switches reads as all-enabled - the public
		// link looks exactly as it did before the feature landed.
		self::assertSame(
			['contacts' => true, 'attachments' => true, 'github' => true, 'timeTracking' => true, 'coverColor' => true],
			$payload['board']['cardFeatures']
		);

		// Card: exactly the whitelisted, people-free field set.
		$cardKeys = array_keys($payload['cards'][0]);
		sort($cardKeys);
		// `type` is a public-safe display attribute (renders as a tile icon like a
		// label/cover - no PII, no internal identifier), so it is a permitted key.
		// `coverColor`, `startDate` and `estimate` (#3951) are presentational,
		// non-person card content too - they carry no assignee/comment/member data.
		self::assertSame(
			['allDay', 'checklist', 'coverColor', 'description', 'duedate', 'estimate', 'humanId', 'id', 'labels', 'priority', 'stackId', 'startDate', 'status', 'title', 'type'],
			$cardKeys
		);

		// With the comments opt-in OFF (the default), no card carries a comment
		// thread at all - the person-free baseline holds.
		self::assertArrayNotHasKey('comments', $payload['cards'][0]);

		// Explicitly assert the sensitive keys never appear. 'waiting' pins the
		// #3746 exclusion: the derived waiting-on-client state is provider-side
		// signal (who the ball is with) and must never ride the anonymous payload.
		// ('comment' is intentionally NOT in this list: the public-safe boolean
		// gate `commentsEnabled` contains that substring; the OFF-state absence of
		// any comment data is asserted directly above.)
		$json = json_encode($payload);
		foreach (['owner', 'assignee', 'acl', 'webhook', 'subscriber', 'watcher', 'reviewState', 'activity', 'waiting'] as $forbidden) {
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
		$this->cardLabelMapper->method('findLabelIdsByBoardPublicOnly')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoardPublicOnly')->willReturn([]);

		$payload = $this->service->getPublicBoard(self::TOKEN);
		self::assertSame('Roadmap', $payload['board']['title']);
	}

	// ── comments opt-in (#3949) ────────────────────────────────────────────

	public function testCommentsOffByDefaultOmitsCommentsAndNeverQueriesThem(): void {
		$this->primePublicBoard();
		// With the opt-in OFF, comments must NEVER be fetched (no leak, no query).
		$this->commentMapper->expects(self::never())->method('findByBoardPublicOnly');
		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertFalse($payload['board']['commentsEnabled']);
		self::assertArrayNotHasKey('comments', $payload['cards'][0]);
	}

	public function testCommentsOnIncludesReadOnlyThreadWithDisplayNamesOnly(): void {
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN, null, true));
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([$this->stack(10, 'To do')]);
		$this->cardMapper->method('findPublicByBoard')->with(1)->willReturn([$this->card(100, 10, 'Live card')]);
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardLabelMapper->method('findLabelIdsByBoardPublicOnly')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoardPublicOnly')->willReturn([]);
		// A top-level comment and a reply, both on the public card.
		$this->commentMapper->expects(self::once())->method('findByBoardPublicOnly')->with(1)->willReturn([
			100 => [
				$this->comment(1, 100, 'bob', 'first!'),
				$this->comment(2, 100, 'carol', 'reply to bob', 1),
			],
		]);
		// The uid resolves to a display name; the uid itself must never appear.
		$bob = $this->createMock(IUser::class);
		$bob->method('getDisplayName')->willReturn('Bob Builder');
		$carol = $this->createMock(IUser::class);
		$carol->method('getDisplayName')->willReturn('Carol Danvers');
		$this->userManager->method('get')->willReturnMap([
			['bob', $bob],
			['carol', $carol],
		]);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertTrue($payload['board']['commentsEnabled']);
		$comments = $payload['cards'][0]['comments'];
		self::assertCount(2, $comments);
		self::assertSame('Bob Builder', $comments[0]['author']);
		self::assertSame('first!', $comments[0]['body']);
		self::assertNull($comments[0]['parentCommentId']);
		self::assertSame('Carol Danvers', $comments[1]['author']);
		self::assertSame(1, $comments[1]['parentCommentId']);

		// Author DISPLAY NAMES only - the uids must not leak, nor any reaction key.
		$json = json_encode($payload);
		self::assertStringNotContainsString('"author":"bob"', $json);
		self::assertStringNotContainsString('"author":"carol"', $json);
		foreach (['reaction', 'reactor', 'assignee', 'watcher', 'member'] as $forbidden) {
			self::assertStringNotContainsStringIgnoringCase($forbidden, $json, "public comments leaked '$forbidden'");
		}
	}

	public function testDeletedCommentAuthorShowsGenericLabelNotRawUid(): void {
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN, null, true));
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([$this->stack(10, 'To do')]);
		$this->cardMapper->method('findPublicByBoard')->with(1)->willReturn([$this->card(100, 10, 'Live card')]);
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardLabelMapper->method('findLabelIdsByBoardPublicOnly')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoardPublicOnly')->willReturn([]);
		// A comment whose author account was deleted: 'ghostuid' is a real,
		// identifying uid that must NEVER surface on the anonymous link.
		$this->commentMapper->expects(self::once())->method('findByBoardPublicOnly')->with(1)->willReturn([
			100 => [
				$this->comment(1, 100, 'ghostuid', 'left before deletion'),
			],
		]);
		// The deleted account no longer resolves - IUserManager::get() returns null.
		$this->userManager->method('get')->with('ghostuid')->willReturn(null);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		$comments = $payload['cards'][0]['comments'];
		self::assertCount(1, $comments);
		// The generic label is shown instead of the raw uid.
		self::assertSame('Former user', $comments[0]['author']);
		self::assertSame('left before deletion', $comments[0]['body']);

		// The raw uid must appear NOWHERE in the serialized public payload.
		$json = json_encode($payload);
		self::assertStringNotContainsString('ghostuid', $json, 'deleted author uid leaked into the public payload');
	}

	public function testSetCommentsRequiresManageAndPersists(): void {
		$board = $this->board(1, self::TOKEN, null, false);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/p/tok');
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertTrue($b->getPublicShareComments());
				return $b;
			});

		$config = $this->service->setComments(1, true, 'alice');
		self::assertTrue($config['commentsEnabled']);
	}

	public function testSetCommentsDeniedWithoutManage(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->boardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->setComments(1, true, 'mallory');
	}
}

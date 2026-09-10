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
use OCA\Kanso\Service\MentionService;
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
		// shape: one boolean per built-in card section saying which ones this board
		// renders, so the public link honours the manager's switches. No PII, no
		// internal identifier, nothing about a person.
		$boardKeys = array_keys($payload['board']);
		sort($boardKeys);
		self::assertSame(['cardFeatures', 'color', 'commentsEnabled', 'prefix', 'title'], $boardKeys);
		self::assertFalse($payload['board']['commentsEnabled']);
		// A board that never touched the switches reads as all-enabled - the public
		// link looks exactly as it did before the feature landed.
		self::assertSame(
			['contacts' => true, 'attachments' => true, 'github' => true, 'timeTracking' => true, 'coverColor' => true, 'checklist' => true],
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

	// ── @mention redaction in free text ───────────────────────────────────
	//
	// A mention has NO entity table: it is the literal string `@uid` inside a card
	// description or a comment body, and those two fields are the only place a real
	// LOGIN uid can ride the anonymous payload as "board content". The description
	// case holds in the DEFAULT configuration (comments opt-in off), so these pin
	// the same "uid never leaves" invariant the author byline already has.

	/**
	 * One public card with the given description, and optionally an opted-in
	 * comment thread. Deliberately separate from primePublicBoard() so each test
	 * owns its fixture text.
	 *
	 * @param Comment[] $comments
	 */
	private function primeCardWithText(string $description, array $comments = []): void {
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN, null, $comments !== []));
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([$this->stack(10, 'To do')]);
		$card = $this->card(100, 10, 'Live card');
		$card->setDescription($description);
		$this->cardMapper->method('findPublicByBoard')->with(1)->willReturn([$card]);
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardLabelMapper->method('findLabelIdsByBoardPublicOnly')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoardPublicOnly')->willReturn([]);
		if ($comments !== []) {
			$this->commentMapper->method('findByBoardPublicOnly')->with(1)->willReturn([100 => $comments]);
		}
	}

	private function liveUser(string $displayName): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		return $user;
	}

	public function testDescriptionMentionShipsDisplayNameNotUid(): void {
		// The default configuration: comments opt-in OFF, description still public.
		$this->primeCardWithText('@jsmith please review before Friday');
		$this->userManager->method('get')->willReturnMap([['jsmith', $this->liveUser('Jane Smith')]]);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertFalse($payload['board']['commentsEnabled']);
		self::assertSame('Jane Smith please review before Friday', $payload['cards'][0]['description']);
		// The uid must appear NOWHERE in the serialized anonymous payload.
		self::assertStringNotContainsString('jsmith', json_encode($payload), 'mentioned uid leaked into the public payload');
	}

	public function testCommentBodyMentionShipsDisplayNameNotUid(): void {
		$this->primeCardWithText('no mentions here', [
			$this->comment(1, 100, 'bob', 'cc @jsmith on this one'),
		]);
		$this->userManager->method('get')->willReturnMap([
			['bob', $this->liveUser('Bob Builder')],
			['jsmith', $this->liveUser('Jane Smith')],
		]);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		$comments = $payload['cards'][0]['comments'];
		self::assertSame('Bob Builder', $comments[0]['author']);
		self::assertSame('cc Jane Smith on this one', $comments[0]['body']);
		// Neither the author's uid nor the MENTIONED uid survives.
		$json = json_encode($payload);
		self::assertStringNotContainsString('jsmith', $json, 'mentioned uid leaked out of a public comment body');
		self::assertStringNotContainsString('"bob"', $json);
	}

	public function testNonResolvableAtStringsAreLeftByteIdentical(): void {
		// `@`-shaped text that is NOT an account: an email address (which the shared
		// MENTION_PATTERN never matches at all), a plain handle, a time, a hyphenated
		// token. Over-eager substitution here would corrupt real board content, so
		// this is as load-bearing as the redaction itself.
		$text = 'mail foo@bar.com, follow @nextcloud, standup @9.30, ping @nosuchuser-42 later';
		$this->primeCardWithText($text);
		// Every lookup misses: none of these tokens is an account.
		$this->userManager->method('get')->willReturn(null);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertSame($text, $payload['cards'][0]['description']);
	}

	public function testMentionThatSwallowsTrailingPunctuationIsStillRedacted(): void {
		// `.`, `-` and `_` are legal uid characters, so a mention ending a sentence
		// captures the punctuation: the token is `jsmith.`, not `jsmith`. Without the
		// trailing-punctuation retry the lookup misses and the uid ships verbatim -
		// a bypass reachable by writing an ordinary English sentence.
		// (`_@jsmith_` is deliberately absent: the shared pattern's negative
		// lookbehind treats a `@` preceded by a word char as not-a-mention at all, so
		// the server would not notify for it and the client would not chip it either.
		// Widening that is a change to MentionService's semantics, not to this
		// redaction.)
		$this->primeCardWithText('ping @jsmith. dash @jsmith- under @jsmith_ done');
		$this->userManager->method('get')->willReturnMap([['jsmith', $this->liveUser('Jane Smith')]]);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertSame(
			'ping Jane Smith. dash Jane Smith- under Jane Smith_ done',
			$payload['cards'][0]['description']
		);
		self::assertStringNotContainsString('jsmith', json_encode($payload));
	}

	public function testATokenNotEndingInPunctuationIsNeverSplit(): void {
		// The other side of that retry: `@bob.smith` is NOT a mention of `bob` (the
		// server would not notify bob either), so it must be looked up whole and left
		// alone - never rewritten to "Bob Builder.smith".
		$this->primeCardWithText('ask @bob.smith about it');
		$this->userManager->method('get')->willReturnMap([
			['bob.smith', null],
			['bob', $this->liveUser('Bob Builder')],
		]);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertSame('ask @bob.smith about it', $payload['cards'][0]['description']);
	}

	public function testMentionOfDeletedAccountKeepsItsLiteralTextWhileTheBylineDoesNot(): void {
		// A DELETED account: the comment AUTHOR field is known to be a uid, so it
		// still becomes the generic label. A bare `@token` in free text is NOT known
		// to be a uid - it is indistinguishable from prose - so it is left alone
		// rather than rewritten, which is the deliberate asymmetry between the two.
		$this->primeCardWithText('@ghostuid used to own this', [
			$this->comment(1, 100, 'ghostuid', 'and @ghostuid said so'),
		]);
		$this->userManager->method('get')->with('ghostuid')->willReturn(null);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertSame('Former user', $payload['cards'][0]['comments'][0]['author']);
		self::assertSame('@ghostuid used to own this', $payload['cards'][0]['description']);
		self::assertSame('and @ghostuid said so', $payload['cards'][0]['comments'][0]['body']);
	}

	public function testOrdinaryNamesAreSubstitutedWithoutEscapeNoise(): void {
		// The description is served BOTH as markdown (the card detail renders it with
		// v-html) and as raw source printed verbatim (the board tile interpolates it as
		// text - src/views/PublicBoard.vue:51). So escaping punctuation unconditionally
		// would show "Anne\-Marie Dubois" to every anonymous visitor, and hyphens,
		// apostrophes, parentheses and sentence dots are what real names are made of.
		// None of them can open a markdown construct, so none of them is escaped.
		$this->primeCardWithText('@a and @b and @c and @d');
		$this->userManager->method('get')->willReturnMap([
			['a', $this->liveUser('Anne-Marie Dubois')],
			['b', $this->liveUser("Sinead O'Brien")],
			['c', $this->liveUser('Dana Smith (Acme)')],
			['d', $this->liveUser('Zoe Ünicode Jr.')],
		]);

		self::assertSame(
			"Anne-Marie Dubois and Sinead O'Brien and Dana Smith (Acme) and Zoe Ünicode Jr.",
			$this->service->getPublicBoard(self::TOKEN)['cards'][0]['description']
		);
	}

	public function testADisplayNameCannotInjectMarkdownIntoSomebodyElsesText(): void {
		// The name is spliced into ANOTHER author's text, so a mentioned user who
		// renames themselves must not thereby inject a link or formatting into it. The
		// escaped form renders as the literal characters (CommonMark), and the
		// domain-shaped name must not survive `linkify: true` as a live link either.
		$this->primeCardWithText('ask @trickster or @domainy about it');
		$this->userManager->method('get')->willReturnMap([
			['trickster', $this->liveUser('[click](https://evil.example)')],
			['domainy', $this->liveUser('www.evil.example')],
		]);

		self::assertSame(
			'ask \[click\]\(https\:\/\/evil\.example\) or www\.evil\.example about it',
			$this->service->getPublicBoard(self::TOKEN)['cards'][0]['description']
		);
	}

	public function testAnAccountWithNothingToShowFallsBackToTheGenericLabel(): void {
		// The account EXISTS (so the token really is a uid) but has no displayable
		// name. The one thing that must not happen is answering with the raw uid.
		$this->primeCardWithText('ping @blankname now');
		$this->userManager->method('get')->willReturnMap([['blankname', $this->liveUser('   ')]]);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertSame('ping Former user now', $payload['cards'][0]['description']);
		self::assertStringNotContainsString('blankname', json_encode($payload));
	}

	public function testAResolvingUidIsRedactedInEveryContextItAppearsIn(): void {
		// Pins the deliberate decision, so nobody has to re-derive it: a token that IS
		// a real uid is substituted even inside a URL path or a code span, because the
		// uid is what must not leave - the link breaking is the lesser cost, and the
		// authenticated renderer already chips the URL case. The non-account tokens in
		// the same fixture make this a MIXED case: greedy over-substitution fails here
		// just as a missed substitution does.
		$this->primeCardWithText('see https://forge.example/@jsmith and `@jsmith` but not @nobody or foo@bar.com');
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === 'jsmith' ? $this->liveUser('Jane Smith') : null
		);

		self::assertSame(
			'see https://forge.example/Jane Smith and `Jane Smith` but not @nobody or foo@bar.com',
			$this->service->getPublicBoard(self::TOKEN)['cards'][0]['description']
		);
	}

	public function testOneUserLookupPerDistinctUidAcrossTheWholeBoard(): void {
		// The board-read hot path: a uid named by many cards (and by a comment
		// author) must cost ONE IUserManager::get(), not one per mention. The cache
		// is shared across cards and across both free-text fields.
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN, null, true));
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([$this->stack(10, 'To do')]);
		$cards = [];
		foreach ([100, 101, 102] as $id) {
			$card = $this->card($id, 10, 'Card ' . $id);
			$card->setDescription('@jsmith and @jsmith again');
			$cards[] = $card;
		}
		$this->cardMapper->method('findPublicByBoard')->with(1)->willReturn($cards);
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardLabelMapper->method('findLabelIdsByBoardPublicOnly')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoardPublicOnly')->willReturn([]);
		$this->commentMapper->method('findByBoardPublicOnly')->with(1)->willReturn([
			100 => [$this->comment(1, 100, 'jsmith', 'mine, and @jsmith again')],
		]);
		// SIX mentions plus one author byline, all naming the same uid: exactly one
		// lookup.
		$this->userManager->expects(self::once())->method('get')->with('jsmith')
			->willReturn($this->liveUser('Jane Smith'));

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertSame('Jane Smith and Jane Smith again', $payload['cards'][0]['description']);
		self::assertSame('Jane Smith', $payload['cards'][0]['comments'][0]['author']);
	}

	public function testDistinctMentionLookupsAreBoundedPerField(): void {
		// The bound exists so the amount of TEXT in a field cannot set the number of
		// user-backend lookups one anonymous request makes. 250 DISTINCT tokens, all
		// of them real accounts: only MentionService::MAX_MENTIONS are resolved, which
		// is exactly the set the WRITE path acts on - a mention past that count never
		// notified anybody either. The rest are left verbatim rather than mangled.
		$tokens = [];
		for ($i = 1; $i <= 250; $i++) {
			$tokens[] = '@user' . $i;
		}
		$this->primeCardWithText(implode(' ', $tokens));
		$this->userManager->expects(self::exactly(MentionService::MAX_MENTIONS))->method('get')
			->willReturnCallback(fn (string $uid): IUser => $this->liveUser('Name of ' . $uid));

		$description = (string)$this->service->getPublicBoard(self::TOKEN)['cards'][0]['description'];

		self::assertStringContainsString('Name of user1', $description);
		self::assertStringContainsString('Name of user' . MentionService::MAX_MENTIONS, $description);
		// Past the bound the raw token survives - documented, and only reachable by
		// content an EDIT user deliberately stuffed with hundreds of distinct tokens.
		self::assertStringContainsString('@user250', $description);
	}

	public function testOneCardsJunkTokensCannotDeRedactAnotherCard(): void {
		// The bound is per FIELD for this reason: a shared, board-wide budget would
		// let ONE card padded with non-account `@tokens` (a pasted log, a CSV, an
		// address dump - no attacker needed) spend it, and every LATER card's real
		// mention would then ship its uid verbatim.
		$this->boardMapper->method('findByPublicToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN));
		$this->stackMapper->method('findByBoard')->with(1)->willReturn([$this->stack(10, 'To do')]);
		$junk = [];
		for ($i = 1; $i <= MentionService::MAX_MENTIONS * 2; $i++) {
			$junk[] = '@notauser' . $i;
		}
		$padded = $this->card(100, 10, 'Pasted log');
		$padded->setDescription(implode(' ', $junk));
		$real = $this->card(101, 10, 'Real work');
		$real->setDescription('@jsmith please review');
		$this->cardMapper->method('findPublicByBoard')->with(1)->willReturn([$padded, $real]);
		$this->labelMapper->method('findByBoard')->willReturn([]);
		$this->cardLabelMapper->method('findLabelIdsByBoardPublicOnly')->willReturn([]);
		$this->checklistItemMapper->method('progressByBoardPublicOnly')->willReturn([]);
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === 'jsmith' ? $this->liveUser('Jane Smith') : null
		);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertSame('Jane Smith please review', $payload['cards'][1]['description']);
		self::assertStringNotContainsString('jsmith', json_encode($payload));
	}

	public function testCommentAuthorLookupsDoNotSpendTheMentionBudget(): void {
		// The bound counts only the lookups the REDACTION starts, and it is local to
		// one field. Author bylines have to resolve either way, so a busy board - 250
		// distinct commenters, far past the bound - must not leave the description's
		// own mention unredacted.
		$comments = [];
		for ($i = 1; $i <= 250; $i++) {
			$comments[] = $this->comment($i, 100, 'commenter' . $i, 'nothing to redact here');
		}
		$this->primeCardWithText('@jsmith please review', $comments);
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid): IUser => $this->liveUser($uid === 'jsmith' ? 'Jane Smith' : 'Commenter ' . $uid)
		);

		$payload = $this->service->getPublicBoard(self::TOKEN);

		self::assertCount(250, $payload['cards'][0]['comments']);
		self::assertSame('Jane Smith please review', $payload['cards'][0]['description']);
	}

	public function testStoredRowsAreNeverRewritten(): void {
		// Payload-only: the mention must keep working for authenticated viewers, so
		// nothing in the public read may write back through a mapper.
		$this->primeCardWithText('@jsmith please review');
		$this->userManager->method('get')->willReturnMap([['jsmith', $this->liveUser('Jane Smith')]]);
		$this->cardMapper->expects(self::never())->method('update');
		$this->commentMapper->expects(self::never())->method('update');
		$this->boardMapper->expects(self::never())->method('update');

		$this->service->getPublicBoard(self::TOKEN);
		$this->addToAssertionCount(1);
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

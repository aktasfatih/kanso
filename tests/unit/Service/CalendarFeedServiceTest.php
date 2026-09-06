<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Service\CalendarFeedService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CalendarFeedServiceTest extends TestCase {
	private const TOKEN = 'a-very-long-unguessable-ical-token-value-0123456789';

	private BoardMapper&MockObject $boardMapper;
	private CardMapper&MockObject $cardMapper;
	private ChangeMapper&MockObject $changeMapper;
	private PermissionService&MockObject $permissionService;
	private ISecureRandom&MockObject $secureRandom;
	private IURLGenerator&MockObject $urlGenerator;
	private CalendarFeedService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->changeMapper = $this->createMock(ChangeMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = new CalendarFeedService(
			$this->boardMapper,
			$this->cardMapper,
			$this->changeMapper,
			$this->permissionService,
			$this->secureRandom,
			$this->urlGenerator,
		);
	}

	private function board(int $id = 1, ?string $token = self::TOKEN): Board {
		$b = new Board();
		$b->setId($id);
		$b->setOwner('alice');
		$b->setTitle('Roadmap');
		$b->setDeletedAt(0);
		$b->setIcalFeedToken($token);
		return $b;
	}

	private function card(int $id, string $title, ?\DateTime $due, bool $allDay = false): Card {
		$c = new Card();
		$c->setId($id);
		$c->setBoardId(1);
		$c->setStackId(10);
		$c->setTitle($title);
		$c->setDuedate($due);
		$c->setAllDay($allDay);
		return $c;
	}

	// ── config (MANAGE) ────────────────────────────────────────────────────

	public function testEnableMintsTokenRequiresManageAndReturnsUrl(): void {
		$board = $this->board(1, null);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->secureRandom->expects(self::once())->method('generate')
			->with(64, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('freshtoken');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/apps/kanso/feed/freshtoken.ics');
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertSame('freshtoken', $b->getIcalFeedToken());
				return $b;
			});

		$result = $this->service->enable(1, 'alice');
		self::assertTrue($result['enabled']);
		self::assertSame('freshtoken', $result['token']);
		self::assertSame('https://nc/apps/kanso/feed/freshtoken.ics', $result['url']);
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
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/apps/kanso/feed/rotatedtoken.ics');
		$this->boardMapper->expects(self::once())->method('update')
			->willReturnCallback(function (Board $b): Board {
				self::assertSame('rotatedtoken', $b->getIcalFeedToken());
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
				self::assertNull($b->getIcalFeedToken());
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
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/apps/kanso/feed/tok.ics');

		$config = $this->service->getConfig(1, 'alice');
		self::assertTrue($config['enabled']);
		self::assertSame(self::TOKEN, $config['token']);
		self::assertSame('https://nc/apps/kanso/feed/tok.ics', $config['url']);
	}

	public function testGetConfigReportsDisabledWhenNoToken(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1, null));

		$config = $this->service->getConfig(1, 'alice');
		self::assertFalse($config['enabled']);
		self::assertNull($config['token']);
		self::assertNull($config['url']);
	}

	// ── public feed (unauthenticated) ──────────────────────────────────────

	private function primeFeed(array $cards): void {
		$this->boardMapper->method('findByIcalFeedToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN));
		// The feed's card read is HARD-CAPPED: an anonymous fetch may never turn
		// into an unbounded scan, so the cap is asserted right here in the priming.
		$this->cardMapper->method('findWithDuedateByBoard')
			->with(1, CalendarFeedService::MAX_EVENTS)->willReturn($cards);
		// Event links use the fragment-free deep-link server route (#3744).
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args = []): string => $route === 'kanso.deepLink.card'
				? 'https://nc/apps/kanso/card/' . $args['id']
				: 'https://nc/apps/kanso/'
		);
	}

	public function testFeedRendersValidVCalendarWithOneEventPerDueCard(): void {
		$this->primeFeed([
			$this->card(100, 'Timed task', new \DateTime('2026-09-01 14:30:00', new \DateTimeZone('UTC'))),
			$this->card(101, 'All-day task', new \DateTime('2026-09-05 00:00:00', new \DateTimeZone('UTC')), true),
		]);

		$ics = $this->service->renderFeed(self::TOKEN);

		// Valid VCALENDAR envelope.
		self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
		self::assertStringContainsString('END:VCALENDAR', $ics);
		self::assertStringContainsString('PRODID:-//Kanso//Card due dates//EN', $ics);
		self::assertStringContainsString('X-WR-CALNAME:Roadmap', $ics);

		// Exactly one VEVENT per due card.
		self::assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
		self::assertSame(2, substr_count($ics, 'END:VEVENT'));

		// Titles carried as SUMMARY; stable per-card UID; deep link present.
		self::assertStringContainsString('SUMMARY:Timed task', $ics);
		self::assertStringContainsString('SUMMARY:All-day task', $ics);
		self::assertStringContainsString('UID:kanso-card-100@1', $ics);
		self::assertStringContainsString('UID:kanso-card-101@1', $ics);
		// Fragment-free server-route deep link (#3744) - survives login redirects.
		self::assertStringContainsString('/apps/kanso/card/100', $ics);
		self::assertMatchesRegularExpression('#URL[^:]*:https://nc/apps/kanso/card/100#', $ics);
		self::assertStringNotContainsString('#/board/', $ics);

		// A parser round-trips it and confirms the event count.
		$vcal = \Sabre\VObject\Reader::read($ics);
		self::assertCount(2, $vcal->VEVENT);
	}

	public function testAllDayEventUsesDateValuedDtstart(): void {
		$this->primeFeed([
			$this->card(101, 'All-day task', new \DateTime('2026-09-05 00:00:00', new \DateTimeZone('UTC')), true),
		]);

		$ics = $this->service->renderFeed(self::TOKEN);

		// An all-day due honours the all-day flag (#3520): DATE-valued DTSTART with
		// no time component, and no DTEND (a single all-day marker).
		self::assertMatchesRegularExpression('/DTSTART;VALUE=DATE:20260905\b/', $ics);
		self::assertStringNotContainsString('DTSTART:20260905T', $ics);
	}

	public function testTimedEventUsesDateTimeDtstartAndDtend(): void {
		$this->primeFeed([
			$this->card(100, 'Timed task', new \DateTime('2026-09-01 14:30:00', new \DateTimeZone('UTC'))),
		]);

		$ics = $this->service->renderFeed(self::TOKEN);

		// A timed due date is a point in time: DTSTART and DTEND both carry the time.
		self::assertMatchesRegularExpression('/DTSTART:20260901T143000/', $ics);
		self::assertMatchesRegularExpression('/DTEND:20260901T143000/', $ics);
	}

	public function testTimedEventIsNormalisedToUtc(): void {
		// A due date carried in a non-UTC zone must be emitted as an unambiguous
		// UTC instant so a subscriber never sees a shifted hour. 09:30 in +02:00
		// is 07:30Z.
		$this->primeFeed([
			$this->card(100, 'Timed task', new \DateTime('2026-09-01 09:30:00', new \DateTimeZone('+02:00'))),
		]);

		$ics = $this->service->renderFeed(self::TOKEN);

		self::assertMatchesRegularExpression('/DTSTART:20260901T073000Z/', $ics);
		self::assertMatchesRegularExpression('/DTEND:20260901T073000Z/', $ics);
	}

	public function testFeedExposesOnlyTitleDueAndLink(): void {
		$card = $this->card(100, 'Timed task', new \DateTime('2026-09-01 14:30:00', new \DateTimeZone('UTC')));
		// Give the card fields that must NEVER reach the feed.
		$card->setDescription('secret internal notes');
		$card->setOwner('alice');
		$this->primeFeed([$card]);

		$ics = $this->service->renderFeed(self::TOKEN);

		// The card description and owner must not leak into the feed body.
		self::assertStringNotContainsString('secret internal notes', $ics);
		self::assertStringNotContainsStringIgnoringCase('alice', $ics);
	}

	public function testEmptyFeedForBoardWithNoDueCards(): void {
		$this->primeFeed([]);
		$ics = $this->service->renderFeed(self::TOKEN);

		self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
		self::assertStringContainsString('END:VCALENDAR', $ics);
		self::assertSame(0, substr_count($ics, 'BEGIN:VEVENT'));
	}

	public function testUnknownTokenIs404(): void {
		$this->boardMapper->method('findByIcalFeedToken')
			->willThrowException(new DoesNotExistException('no such token'));

		$this->expectException(DoesNotExistException::class);
		$this->service->renderFeed('does-not-exist');
	}

	public function testDisabledTokenIs404(): void {
		// A board whose token was concurrently cleared must not be served even if
		// somehow returned by the lookup (defence in depth).
		$this->boardMapper->method('findByIcalFeedToken')->willReturn($this->board(1, null));

		$this->expectException(DoesNotExistException::class);
		$this->service->renderFeed(self::TOKEN);
	}

	public function testFeedIsCappedSoAnAnonymousFetchCannotScanAWholeBoard(): void {
		// The whole point of the cap: the row count a single unauthenticated GET
		// can pull is a CONSTANT, never a function of board size.
		$this->boardMapper->method('findByIcalFeedToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN));
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc/apps/kanso/card/1');
		$seenLimit = null;
		$this->cardMapper->expects(self::once())->method('findWithDuedateByBoard')
			->willReturnCallback(function (int $boardId, int $limit) use (&$seenLimit): array {
				$seenLimit = $limit;
				return [];
			});

		$this->service->renderFeed(self::TOKEN);

		self::assertSame(CalendarFeedService::MAX_EVENTS, $seenLimit, 'the feed must pass its cap down to the query');
		self::assertGreaterThan(0, CalendarFeedService::MAX_EVENTS);
	}

	// ── conditional feed (ETag / If-None-Match) ────────────────────────────

	public function testFeedEtagIsTheBoardsLatestChangeId(): void {
		$this->boardMapper->method('findByIcalFeedToken')->with(self::TOKEN)
			->willReturn($this->board(1, self::TOKEN));
		$this->changeMapper->expects(self::once())->method('getLatestChangeId')
			->with(1)->willReturn(4711);
		// Never touches the cards: the validator is what makes the poll cheap.
		$this->cardMapper->expects(self::never())->method('findWithDuedateByBoard');

		self::assertSame('4711', $this->service->feedEtag(self::TOKEN));
	}

	public function testFeedEtagRejectsAnUnknownTokenLikeTheFeedItself(): void {
		// The conditional probe must be no more of an oracle than the plain fetch.
		$this->boardMapper->method('findByIcalFeedToken')
			->willThrowException(new DoesNotExistException('no such token'));

		$this->expectException(DoesNotExistException::class);
		$this->service->feedEtag('does-not-exist');
	}

	public function testFeedEtagRejectsADisabledToken(): void {
		$this->boardMapper->method('findByIcalFeedToken')->willReturn($this->board(1, null));

		$this->expectException(DoesNotExistException::class);
		$this->service->feedEtag(self::TOKEN);
	}
}

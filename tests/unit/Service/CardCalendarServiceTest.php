<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Service\CardCalendarService;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardCalendarServiceTest extends TestCase {
	private const PRINCIPAL = 'principals/users/alice';

	private BoardMapper&MockObject $boardMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardAccess&MockObject $boardAccess;
	private PermissionService&MockObject $permissionService;
	private IURLGenerator&MockObject $urlGenerator;
	private CardCalendarService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(static fn (string $route, array $args): string => 'https://nc/index.php/apps/kanso/card/' . $args['id']);
		$this->service = new CardCalendarService(
			$this->boardMapper,
			$this->cardMapper,
			$this->boardAccess,
			$this->permissionService,
			$this->urlGenerator,
		);
	}

	private function board(int $id = 7, string $owner = 'alice', ?string $color = '0082c9', bool $archived = false, int $deletedAt = 0): Board {
		$b = new Board();
		$b->setId($id);
		$b->setOwner($owner);
		$b->setTitle('Roadmap');
		$b->setColor($color);
		$b->setArchived($archived);
		$b->setDeletedAt($deletedAt);
		$b->setLastModified(1000);
		return $b;
	}

	private function card(int $id, string $title, ?\DateTime $due, bool $allDay = false, int $doneAt = 0): Card {
		$c = new Card();
		$c->setId($id);
		$c->setBoardId(7);
		$c->setStackId(10);
		$c->setTitle($title);
		$c->setDuedate($due);
		$c->setAllDay($allDay);
		$c->setDoneAt($doneAt);
		$c->setCreatedAt(1_700_000_000);
		$c->setLastModified(1_700_000_500);
		return $c;
	}

	private function member(int $boardId = 7): ViewerContext {
		return ViewerContext::forMember('alice', $boardId, ViewerContext::ROLE_INTERNAL, true);
	}

	// ── principal parsing ──────────────────────────────────────────────────

	public function testPrincipalUidAcceptsOnlyUserPrincipals(): void {
		self::assertSame('alice', $this->service->principalUid('principals/users/alice'));
		self::assertNull($this->service->principalUid('principals/groups/team'));
		self::assertNull($this->service->principalUid('principals/system/system'));
		self::assertNull($this->service->principalUid('principals/users/'));
		self::assertNull($this->service->principalUid('nonsense'));
	}

	// ── uri / name round-trips ─────────────────────────────────────────────

	public function testCalendarUriRoundTrips(): void {
		$board = $this->board(42);
		self::assertSame('board-42', $this->service->calendarUri($board));
		self::assertSame(42, $this->service->boardIdFromCalendarUri('board-42'));
		self::assertNull($this->service->boardIdFromCalendarUri('board-'));
		self::assertNull($this->service->boardIdFromCalendarUri('board-x'));
		self::assertNull($this->service->boardIdFromCalendarUri('personal'));
	}

	public function testObjectNameRoundTrips(): void {
		$card = $this->card(99, 'x', null);
		self::assertSame('kanso-card-99.ics', $this->service->objectName($card));
		self::assertSame(99, $this->service->cardIdFromObjectName('kanso-card-99.ics'));
		self::assertNull($this->service->cardIdFromObjectName('kanso-card-.ics'));
		self::assertNull($this->service->cardIdFromObjectName('kanso-card-99'));
		self::assertNull($this->service->cardIdFromObjectName('other.ics'));
	}

	// ── board listing ──────────────────────────────────────────────────────

	public function testBoardsForPrincipalSkipsArchivedAndNonUserPrincipals(): void {
		$this->permissionService->method('getUserGroupIds')->with('alice')->willReturn(['team']);
		$this->boardMapper->method('findAllForUser')->with('alice', ['team'])->willReturn([
			$this->board(1),
			$this->board(2, archived: true),
			$this->board(3),
		]);

		$boards = $this->service->boardsForPrincipal(self::PRINCIPAL);
		self::assertSame([1, 3], array_map(static fn (Board $b): int => (int)$b->getId(), $boards));

		// A group/system principal gets nothing (never even queries the mapper).
		self::assertSame([], $this->service->boardsForPrincipal('principals/groups/team'));
	}

	public function testBoardForPrincipalReturnsBoardForMember(): void {
		$board = $this->board(7);
		$this->boardMapper->method('find')->with(7)->willReturn($board);
		$this->boardAccess->expects(self::once())->method('contextFor')
			->with($board, 'alice')->willReturn($this->member());

		self::assertSame($board, $this->service->boardForPrincipal(self::PRINCIPAL, 'board-7'));
	}

	public function testBoardForPrincipalNullForNonMember(): void {
		$board = $this->board(7);
		$this->boardMapper->method('find')->with(7)->willReturn($board);
		$this->boardAccess->method('contextFor')
			->willThrowException(new NotAMemberException('nope'));

		self::assertNull($this->service->boardForPrincipal(self::PRINCIPAL, 'board-7'));
	}

	public function testBoardForPrincipalNullForDeletedArchivedOrMissing(): void {
		// Deleted board.
		$this->boardMapper->method('find')->willReturnCallback(function (int $id): Board {
			if ($id === 8) {
				throw new DoesNotExistException('gone');
			}
			return $this->board(9, deletedAt: $id === 9 ? 12345 : 0, archived: $id === 10);
		});

		self::assertNull($this->service->boardForPrincipal(self::PRINCIPAL, 'board-8')); // missing
		self::assertNull($this->service->boardForPrincipal(self::PRINCIPAL, 'board-9')); // deleted
		self::assertNull($this->service->boardForPrincipal(self::PRINCIPAL, 'board-10')); // archived
		self::assertNull($this->service->boardForPrincipal(self::PRINCIPAL, 'garbage')); // bad uri
	}

	public function testDueCardsScopesToViewer(): void {
		$board = $this->board(7);
		$viewer = $this->member();
		$cards = [$this->card(1, 'a', new \DateTime('2026-08-15T09:30:00+00:00'))];
		$this->boardAccess->method('contextFor')->with($board, 'alice')->willReturn($viewer);
		$this->cardMapper->expects(self::once())->method('findDuedateSummariesByBoard')
			->with(7, $viewer)->willReturn($cards);

		self::assertSame($cards, $this->service->dueCards($board, 'alice'));
	}

	// ── VTODO serialisation ────────────────────────────────────────────────

	public function testSerializeTimedCardEmitsUtcVtodo(): void {
		$board = $this->board(7);
		$card = $this->card(3, 'Ship it', new \DateTime('2026-08-15T09:30:00+00:00'));

		$ics = $this->service->serializeCard($board, $card);
		self::assertStringContainsString('BEGIN:VTODO', $ics);
		self::assertStringContainsString('UID:kanso-card-3@board-7', $ics);
		self::assertStringContainsString('SUMMARY:Ship it', $ics);
		self::assertStringContainsString('DUE:20260815T093000Z', $ics);
		self::assertStringContainsString('STATUS:NEEDS-ACTION', $ics);
		self::assertStringContainsString('https://nc/index.php/apps/kanso/card/3', $ics);
		self::assertStringNotContainsString('STATUS:COMPLETED', $ics);
		// Exactly one DTSTAMP - the auto-added "now" one must be replaced, not doubled.
		self::assertSame(1, substr_count($ics, 'DTSTAMP:'));
	}

	public function testSerializeAllDayCardEmitsDateValue(): void {
		$board = $this->board(7);
		$card = $this->card(4, 'All day', new \DateTime('2026-08-15T00:00:00+00:00'), allDay: true);

		$ics = $this->service->serializeCard($board, $card);
		self::assertStringContainsString('DUE;VALUE=DATE:20260815', $ics);
		self::assertStringNotContainsString('DUE:20260815T', $ics);
	}

	public function testSerializeDoneCardMarksCompleted(): void {
		$board = $this->board(7);
		$card = $this->card(5, 'Done card', new \DateTime('2026-08-15T09:30:00+00:00'), doneAt: 1_700_001_000);

		$ics = $this->service->serializeCard($board, $card);
		self::assertStringContainsString('STATUS:COMPLETED', $ics);
		self::assertStringContainsString('PERCENT-COMPLETE:100', $ics);
		self::assertStringContainsString('COMPLETED:', $ics);
	}

	// ── etag + colour ──────────────────────────────────────────────────────

	public function testEtagChangesWithLastModified(): void {
		$card = $this->card(6, 'x', null);
		$first = $this->service->etag($card);
		$card->setLastModified(1_700_009_999);
		self::assertNotSame($first, $this->service->etag($card));
	}

	public function testCalendarColorGetsHashPrefix(): void {
		self::assertSame('#0082c9', $this->service->calendarColor($this->board(1, color: '0082c9')));
		self::assertSame('#abc', $this->service->calendarColor($this->board(1, color: '#abc')));
		self::assertNull($this->service->calendarColor($this->board(1, color: null)));
	}
}

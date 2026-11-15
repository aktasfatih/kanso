<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\CalDav;

use OCA\Kanso\CalDav\CardCalendar;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Service\CardCalendarService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\IMultiGet;

require_once __DIR__ . '/../../stubs/caldav.php';

class CardCalendarTest extends TestCase {
	private CardCalendarService&MockObject $service;
	private CardCalendar $calendar;

	protected function setUp(): void {
		parent::setUp();
		$board = new Board();
		$board->setId(7);
		$board->setOwner('board-owner');
		$this->service = $this->getMockBuilder(CardCalendarService::class)
			->disableOriginalConstructor()
			->onlyMethods(['dueCards', 'serializeCard', 'etag', 'lastModified'])
			->getMock();
		$this->service->method('etag')->willReturnCallback(static fn (Card $card): string => '"card-' . $card->getId() . '"');
		$this->service->method('lastModified')->willReturn(1000);
		$this->calendar = new CardCalendar($this->service, $board, 'principals/users/reader');
	}

	private function card(int $id): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId(7);
		return $card;
	}

	public function testMultigetReturnsOnlyExactRequestedFilteredChildrenOnce(): void {
		$readable = $this->card(42);
		$this->service->expects(self::once())->method('dueCards')->with(self::isInstanceOf(Board::class), 'reader')
			->willReturn([$this->card(12), $readable]);
		$this->service->expects(self::once())->method('serializeCard')->with(self::isInstanceOf(Board::class), $readable)->willReturn('calendar-data');

		self::assertInstanceOf(IMultiGet::class, $this->calendar);
		$children = $this->calendar->getMultipleChildren([
			'kanso-card-42.ics', 'kanso-card-43.ics', 'kanso-card-42.ics',
			'kanso-card-044.ics', 'kanso-card-042.ics', 'kanso-card-+42.ics',
			'kanso-card-42.ics/', 'KANSo-card-42.ics', 'kanso-card-42.ICS',
			'../kanso-card-42.ics', 'board-8/kanso-card-42.ics', '/kanso-card-42.ics',
			'https://foreign.example/kanso-card-42.ics', 'kanso-card-%34%32.ics',
			'kanso-card-42.ics?x=1', 'kanso-card-42.ics#x', 'kanso-card-9999999999999999999999999.ics', '',
		]);

		self::assertCount(1, $children);
		self::assertSame('kanso-card-42.ics', $children[0]->getName());
		self::assertSame('calendar-data', $children[0]->get());
		self::assertSame('"card-42"', $children[0]->getETag());
		self::assertSame(1000, $children[0]->getLastModified());
		self::assertSame('principals/users/reader', $children[0]->getOwner());
		self::assertSame([['privilege' => '{DAV:}read', 'principal' => 'principals/users/reader', 'protected' => true]], $children[0]->getACL());
	}

	public function testMultigetReusesSummaryCacheAcrossReadsAndKeepsSummaryOrder(): void {
		$startOnly = $this->card(42);
		$startOnly->setStartDate(new \DateTime('2026-11-20T09:00:00Z'));
		$this->service->expects(self::once())->method('dueCards')->willReturn([$startOnly, $this->card(43)]);
		$this->service->method('serializeCard')->willReturn('calendar-data');

		$children = $this->calendar->getMultipleChildren(['kanso-card-43.ics', 'kanso-card-42.ics']);
		self::assertSame(['kanso-card-42.ics', 'kanso-card-43.ics'], array_map(static fn ($child) => $child->getName(), $children));
		self::assertTrue($this->calendar->childExists('kanso-card-42.ics'));
		$single = $this->calendar->getChild('kanso-card-42.ics');
		self::assertSame($children[0]->get(), $single->get());
		self::assertSame($children[0]->getETag(), $single->getETag());
		foreach ($this->calendar->getChildren() as $child) {
			self::assertSame('principals/users/reader', $child->getOwner());
		}
		self::assertSame([], $this->calendar->getMultipleChildren(['kanso-card-44.ics']));
		self::assertCount(1, $this->calendar->getMultipleChildren(['kanso-card-43.ics']));
	}

	public function testAllMissingDoesNotSerializeUnrequestedTasks(): void {
		$this->service->expects(self::once())->method('dueCards')->willReturn([$this->card(42)]);
		$this->service->expects(self::never())->method('serializeCard');
		self::assertSame([], $this->calendar->getMultipleChildren(['kanso-card-43.ics', '../kanso-card-42.ics', 'kanso-card-042.ics']));
		$this->expectException(NotFound::class);
		$this->calendar->getChild('kanso-card-43.ics');
	}

	public function testEmptyRequestDoesNotLoadSummaries(): void {
		$this->service->expects(self::never())->method('dueCards');
		self::assertSame([], $this->calendar->getMultipleChildren([]));
	}

	public function testNonUserPrincipalCannotLoadTaskData(): void {
		$this->service->expects(self::never())->method('dueCards');
		self::assertSame([], (new CardCalendar($this->service, new Board(), 'principals/groups/team'))->getMultipleChildren(['kanso-card-42.ics']));
	}
}

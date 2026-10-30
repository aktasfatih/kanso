<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\CardContact;
use OCA\Kanso\Db\CardContactMapper;
use OCA\Kanso\Db\CardLink;
use OCA\Kanso\Db\CardLinkMapper;
use OCA\Kanso\Db\CardTimeEntry;
use OCA\Kanso\Db\CardTimeEntryMapper;
use OCA\Kanso\Service\CardMoveMetadataService;
use PHPUnit\Framework\TestCase;

class CardMoveMetadataServiceTest extends TestCase {
	public function testCopiesContactsAllLinksAndTimeEntriesInOriginalOrder(): void {
		$contacts = $this->createMock(CardContactMapper::class);
		$links = $this->createMock(CardLinkMapper::class);
		$timeEntries = $this->createMock(CardTimeEntryMapper::class);
		$service = new CardMoveMetadataService($contacts, $links, $timeEntries);

		$contacts->expects(self::once())->method('findContactsByCard')->with(9)->willReturn([
			['contactUri' => 'contact-1', 'displayName' => 'Alex'],
		]);
		$contacts->expects(self::once())->method('insertLink')->with(42, 'contact-1', 'Alex')->willReturn(new CardContact());

		$sourceLinks = [];
		for ($i = 0; $i < 21; $i++) {
			$link = new CardLink();
			$link->setCardId(9);
			$link->setProvider('forgejo');
			$link->setUrl('https://example.test/issues/' . $i);
			$link->setKind('issue');
			$link->setState('closed');
			$link->setTitle('Issue ' . $i);
			$link->setLastPolled(30 + $i);
			$link->setCreatedAt(10 + $i);
			$sourceLinks[] = $link;
		}
		$links->expects(self::once())->method('findAllByCard')->with(9)->willReturn($sourceLinks);
		$copiedLinks = [];
		$links->expects(self::exactly(21))->method('insert')->willReturnCallback(function (CardLink $link) use (&$copiedLinks): CardLink {
			$copiedLinks[] = $link;
			return $link;
		});

		$newest = $this->timeEntry(600, 'newest', 20);
		$oldest = $this->timeEntry(300, 'oldest', 10);
		$timeEntries->expects(self::once())->method('findByCard')->with(9)->willReturn([$newest, $oldest]);
		$copiedEntries = [];
		$timeEntries->expects(self::exactly(2))->method('insert')->willReturnCallback(function (CardTimeEntry $entry) use (&$copiedEntries): CardTimeEntry {
			$copiedEntries[] = $entry;
			return $entry;
		});

		$service->copy(9, 42, 7);

		foreach ($copiedLinks as $i => $link) {
			self::assertSame(42, $link->getCardId());
			self::assertSame('forgejo', $link->getProvider());
			self::assertSame('https://example.test/issues/' . $i, $link->getUrl());
			self::assertSame('issue', $link->getKind());
			self::assertSame('closed', $link->getState());
			self::assertSame('Issue ' . $i, $link->getTitle());
			self::assertSame(30 + $i, $link->getLastPolled());
			self::assertSame(10 + $i, $link->getCreatedAt());
		}
		self::assertSame(['oldest', 'newest'], array_map(static fn (CardTimeEntry $entry): ?string => $entry->getNote(), $copiedEntries));
		self::assertSame([300, 600], array_map(static fn (CardTimeEntry $entry): int => $entry->getSeconds(), $copiedEntries));
		foreach ($copiedEntries as $i => $entry) {
			self::assertSame(42, $entry->getCardId());
			self::assertSame(7, $entry->getBoardId());
			self::assertSame('alice', $entry->getCreatedBy());
			self::assertSame($i === 0 ? 10 : 20, $entry->getCreatedAt());
		}
	}

	private function timeEntry(int $seconds, string $note, int $createdAt): CardTimeEntry {
		$entry = new CardTimeEntry();
		$entry->setCardId(9);
		$entry->setBoardId(1);
		$entry->setSeconds($seconds);
		$entry->setNote($note);
		$entry->setCreatedBy('alice');
		$entry->setCreatedAt($createdAt);
		return $entry;
	}
}

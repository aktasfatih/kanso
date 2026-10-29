<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\CardActivityHistory;
use OCA\Kanso\Db\CardActivityHistoryMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetail;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\ChangeMapper;
use OCA\Kanso\Service\CardMoveHistoryService;
use PHPUnit\Framework\TestCase;

class CardMoveHistoryServiceTest extends TestCase {
	public function testCopiesPriorSnapshotsAndAllRetainedChangesInOrder(): void {
		$history = $this->createMock(CardActivityHistoryMapper::class);
		$changes = $this->createMock(ChangeMapper::class);
		$details = $this->createMock(ChangeDetailMapper::class);
		$prior = new CardActivityHistory();
		$prior->setId(9);
		$prior->setAction(Change::ACTION_UPDATE);
		$prior->setActor('alice');
		$prior->setVerb(Change::VERB_RENAMED);
		$prior->setCreatedAt(50);
		$prior->setHasDetail(true);
		$prior->setFromText('old title');
		$prior->setToText('new title');
		$history->method('findPage')->with(1, 0, 500)->willReturn([$prior]);

		$entries = [];
		for ($id = 1; $id <= 501; $id++) {
			$entry = new Change();
			$entry->setId($id);
			$entry->setAction(Change::ACTION_UPDATE);
			$entry->setActor('bob');
			$entry->setVerb(Change::VERB_COMMENTED);
			$entry->setCreatedAt(100 + $id);
			$entries[] = $entry;
		}
		$changes->expects(self::exactly(2))->method('findEntityPage')->willReturnCallback(
			static function (int $board, int $type, int $card, int $after, int $limit) use ($entries): array {
				self::assertSame([3, Change::ENTITY_CARD, 1, 500], [$board, $type, $card, $limit]);
				return $after === 0 ? array_slice($entries, 0, 500) : array_slice($entries, 500);
			}
		);
		$detail = new ChangeDetail();
		$detail->setChangeId(501);
		$detail->setFromText(null);
		$detail->setToText('new value');
		$details->method('findByChangeIds')->willReturnCallback(static fn (array $ids): array => in_array(501, $ids, true) ? [501 => $detail] : []);
		$copied = [];
		$history->method('insert')->willReturnCallback(static function (CardActivityHistory $item) use (&$copied): CardActivityHistory {
			$copied[] = $item;
			return $item;
		});

		(new CardMoveHistoryService($history, $changes, $details))->copy(1, 2, 3);

		self::assertCount(502, $copied);
		self::assertSame(2, $copied[0]->getCardId());
		self::assertSame('old title', $copied[0]->getFromText());
		self::assertSame(101, $copied[1]->getCreatedAt());
		self::assertFalse($copied[1]->getHasDetail());
		self::assertSame(601, $copied[501]->getCreatedAt());
		self::assertTrue($copied[501]->getHasDetail());
		self::assertSame('new value', $copied[501]->getToText());
	}
}

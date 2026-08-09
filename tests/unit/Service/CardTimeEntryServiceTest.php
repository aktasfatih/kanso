<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardTimeEntry;
use OCA\Kanso\Db\CardTimeEntryMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\CardTimeEntryService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardTimeEntryServiceTest extends TestCase {
	private CardTimeEntryMapper&MockObject $timeEntryMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissionService;
	private ChangeNotifier&MockObject $changeNotifier;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private CardTimeEntryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->timeEntryMapper = $this->createMock(CardTimeEntryMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);

		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->service = new CardTimeEntryService(
			$this->timeEntryMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissionService,
			$this->changeNotifier,
			$this->visibilityGuard,
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

	public function testListForCardReturnsEntries(): void {
		$this->expectCardLoaded();
		$e = new CardTimeEntry();
		$e->setId(1);
		$e->setCardId(9);
		$e->setSeconds(3600);
		$this->timeEntryMapper->method('findByCard')->with(9)->willReturn([$e]);

		$result = $this->service->listForCard(9, 'bob');
		self::assertCount(1, $result);
		self::assertSame(3600, $result[0]->getSeconds());
	}

	// ---- add (happy path + gating + edge) ---------------------------------

	public function testAddRequiresEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->timeEntryMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->add(9, 3600, 'work', 'stranger');
	}

	public function testAddStoresEntryAndNotifies(): void {
		$this->expectCardLoaded();

		$captured = null;
		$this->timeEntryMapper->method('insert')->willReturnCallback(
			function (CardTimeEntry $e) use (&$captured): CardTimeEntry {
				$e->setId(7);
				$captured = $e;
				return $e;
			}
		);
		// The mutation reuses the card ACTION_UPDATE change row (realtime/delta-sync).
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob');

		$result = $this->service->add(9, 5400, '  Pairing session  ', 'bob');

		self::assertSame(7, $result->getId());
		self::assertSame(9, $captured->getCardId());
		// board_id is denormalized from the loaded card, never client-supplied.
		self::assertSame(1, $captured->getBoardId());
		self::assertSame(5400, $captured->getSeconds());
		// The note is trimmed.
		self::assertSame('Pairing session', $captured->getNote());
		self::assertSame('bob', $captured->getCreatedBy());
	}

	public function testAddStoresNullNoteWhenEmpty(): void {
		$this->expectCardLoaded();
		$captured = null;
		$this->timeEntryMapper->method('insert')->willReturnCallback(
			function (CardTimeEntry $e) use (&$captured): CardTimeEntry {
				$captured = $e;
				return $e;
			}
		);

		$this->service->add(9, 60, '   ', 'bob');
		self::assertNull($captured->getNote());
	}

	public function testAddRejectsZeroSeconds(): void {
		$this->expectCardLoaded();
		$this->timeEntryMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->add(9, 0, null, 'bob');
	}

	public function testAddRejectsNegativeSeconds(): void {
		$this->expectCardLoaded();
		$this->timeEntryMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->add(9, -120, null, 'bob');
	}

	public function testAddRejectsAbsurdlyLargeSeconds(): void {
		$this->expectCardLoaded();
		$this->timeEntryMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->add(9, CardTimeEntryService::MAX_SECONDS + 1, null, 'bob');
	}

	public function testAddRejectsDeletedCard(): void {
		$deleted = $this->card();
		$deleted->setDeletedAt(time());
		$this->cardMapper->method('find')->with(9)->willReturn($deleted);
		$this->timeEntryMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->add(9, 60, null, 'bob');
	}

	// ---- delete (gating + IDOR) -------------------------------------------

	public function testDeleteRequiresEdit(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'stranger', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->timeEntryMapper->expects(self::never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->delete(9, 1, 'stranger');
	}

	public function testDeleteRejectsCrossCardEntry(): void {
		$this->expectCardLoaded();
		$other = new CardTimeEntry();
		$other->setId(5);
		$other->setCardId(99); // belongs to a different card - IDOR guard
		$this->timeEntryMapper->method('find')->with(5)->willReturn($other);
		$this->timeEntryMapper->expects(self::never())->method('delete');

		$this->expectException(DoesNotExistException::class);
		$this->service->delete(9, 5, 'bob');
	}

	public function testDeleteRemovesRowAndNotifies(): void {
		$this->expectCardLoaded();
		$entry = new CardTimeEntry();
		$entry->setId(5);
		$entry->setCardId(9);
		$this->timeEntryMapper->method('find')->with(5)->willReturn($entry);
		$this->timeEntryMapper->expects(self::once())->method('delete')->with($entry);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob');

		$this->service->delete(9, 5, 'bob');
	}

	// ---- deleteAllForCard (cascade on purge) ------------------------------

	public function testDeleteAllForCardDropsRowsWithoutGateOrNotify(): void {
		$this->timeEntryMapper->expects(self::once())->method('deleteByCard')->with(9);
		$this->permissionService->expects(self::never())->method('assertPermission');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->deleteAllForCard(9);
	}
}

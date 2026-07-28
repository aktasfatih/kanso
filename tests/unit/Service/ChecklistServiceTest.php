<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\ChecklistService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SortKeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChecklistServiceTest extends TestCase {
	private ChecklistItemMapper&MockObject $itemMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private ChecklistService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->itemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		// A real SortKeyService - the fractional-key maths is deterministic and
		// central to reorder behaviour, so exercise it rather than mock it.
		$this->service = new ChecklistService(
			$this->itemMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			new SortKeyService(),
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function card(int $id = 9, int $boardId = 1): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId(5);
		$card->setTitle('Existing card');
		$card->setSortKey('I');
		$card->setDeletedAt(0);
		return $card;
	}

	private function item(int $id, string $sortKey, string $title = 'todo', bool $done = false, int $cardId = 9): ChecklistItem {
		$item = new ChecklistItem();
		$item->setId($id);
		$item->setCardId($cardId);
		$item->setTitle($title);
		$item->setDone($done);
		$item->setSortKey($sortKey);
		return $item;
	}

	private function expectCardLoaded(): Board {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	// ---- addItem ----------------------------------------------------------

	public function testAddItemToEmptyChecklistUsesInitialKeyAndWritesCardChangeRow(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->itemMapper->method('findLastByCard')->with(9)->willReturn(null);
		$this->itemMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (ChecklistItem $item): ChecklistItem {
				self::assertSame('I', $item->getSortKey());
				self::assertSame('Buy milk', $item->getTitle());
				self::assertFalse($item->getDone());
				self::assertSame(9, $item->getCardId());
				$item->setId(100);
				return $item;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$saved = $this->service->addItem(9, '  Buy milk  ', 'alice');
		self::assertSame('Buy milk', $saved->getTitle());
	}

	public function testAddItemAppendsAfterLastKey(): void {
		$this->expectCardLoaded();
		$this->itemMapper->method('findLastByCard')->with(9)->willReturn($this->item(1, 'I'));
		$this->itemMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (ChecklistItem $item): ChecklistItem {
				// after('I') must sort strictly after 'I'.
				self::assertGreaterThan(0, strcmp($item->getSortKey(), 'I'));
				return $item;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->addItem(9, 'Second', 'alice');
	}

	public function testAddItemRejectsEmptyTitle(): void {
		$this->expectCardLoaded();
		$this->itemMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->addItem(9, '   ', 'alice');
	}

	public function testAddItemRejectsOverlongTitle(): void {
		$this->expectCardLoaded();
		$this->itemMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->addItem(9, str_repeat('x', 256), 'alice');
	}

	public function testAddItemAssertsActorEditPermission(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->itemMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->addItem(9, 'Nope', 'mallory');
	}

	public function testAddItemRejectsDeletedCard(): void {
		$card = $this->card();
		$card->setDeletedAt(1234);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->itemMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->addItem(9, 'Buy milk', 'alice');
	}

	// ---- listItems --------------------------------------------------------

	public function testListItemsAssertsReadAndReturnsItems(): void {
		$board = $this->expectCardLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'reader', PermissionService::PERMISSION_READ);
		$items = [$this->item(1, 'I'), $this->item(2, 'M')];
		$this->itemMapper->method('findByCard')->with(9)->willReturn($items);

		self::assertSame($items, $this->service->listItems(9, 'reader'));
	}

	// ---- updateItem -------------------------------------------------------

	public function testUpdateItemTogglesDoneAndWritesCardChangeRow(): void {
		$board = $this->board();
		$item = $this->item(50, 'I', 'todo', false);
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertTrue($i->getDone());
				return $i;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$this->service->updateItem(50, null, true, 'alice');
	}

	public function testUpdateItemRenameTrimsTitle(): void {
		$item = $this->item(50, 'I', 'old');
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertSame('new title', $i->getTitle());
				return $i;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->updateItem(50, '  new title  ', null, 'alice');
	}

	public function testUpdateItemNoOpWritesNoChangeRow(): void {
		$item = $this->item(50, 'I', 'todo', true);
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->itemMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		// Same title, same done state → nothing to persist.
		$result = $this->service->updateItem(50, 'todo', true, 'alice');
		self::assertSame($item, $result);
	}

	public function testUpdateItemRejectsEmptyRename(): void {
		$item = $this->item(50, 'I', 'todo');
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->itemMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->updateItem(50, '   ', null, 'alice');
	}

	public function testUpdateItemAssertsActorEditPermission(): void {
		$board = $this->board();
		$item = $this->item(50, 'I');
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->itemMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->updateItem(50, null, true, 'mallory');
	}

	// ---- moveItem ---------------------------------------------------------

	public function testMoveItemToTopUsesKeyBeforeFirstSibling(): void {
		$moved = $this->item(3, 'Z');
		$this->itemMapper->method('find')->with(3)->willReturn($moved);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// Siblings (moved item excluded by the service): first has key 'M'.
		$this->itemMapper->method('findByCard')->with(9)->willReturn([
			$this->item(1, 'M'), $this->item(2, 'T'), $moved,
		]);
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertLessThan(0, strcmp($i->getSortKey(), 'M'));
				return $i;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->moveItem(3, null, 'alice');
	}

	public function testMoveItemBetweenSiblings(): void {
		$moved = $this->item(3, 'A');
		$this->itemMapper->method('find')->with(3)->willReturn($moved);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// Move item 3 to sit after item 1 ('M'), before item 2 ('T').
		$this->itemMapper->method('findByCard')->with(9)->willReturn([
			$this->item(1, 'M'), $this->item(2, 'T'), $moved,
		]);
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertGreaterThan(0, strcmp($i->getSortKey(), 'M'));
				self::assertLessThan(0, strcmp($i->getSortKey(), 'T'));
				return $i;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->moveItem(3, 1, 'alice');
	}

	public function testMoveItemToEndUsesKeyAfterLastSibling(): void {
		$moved = $this->item(3, 'A');
		$this->itemMapper->method('find')->with(3)->willReturn($moved);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->itemMapper->method('findByCard')->with(9)->willReturn([
			$this->item(1, 'M'), $this->item(2, 'T'), $moved,
		]);
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertGreaterThan(0, strcmp($i->getSortKey(), 'T'));
				return $i;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->moveItem(3, 2, 'alice');
	}

	public function testMoveItemRejectsSelfReference(): void {
		$this->itemMapper->expects(self::never())->method('find');
		$this->expectException(InvalidInputException::class);
		$this->service->moveItem(3, 3, 'alice');
	}

	public function testMoveItemRejectsAfterItemFromAnotherCard(): void {
		$moved = $this->item(3, 'A');
		$this->itemMapper->method('find')->with(3)->willReturn($moved);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->itemMapper->method('findByCard')->with(9)->willReturn([
			$this->item(1, 'M'), $moved,
		]);
		$this->itemMapper->expects(self::never())->method('update');

		// 999 is not one of this card's items.
		$this->expectException(InvalidInputException::class);
		$this->service->moveItem(3, 999, 'alice');
	}

	public function testMoveItemAssertsActorEditPermission(): void {
		$board = $this->board();
		$moved = $this->item(3, 'A');
		$this->itemMapper->method('find')->with(3)->willReturn($moved);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->itemMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->moveItem(3, null, 'mallory');
	}

	// ---- deleteItem -------------------------------------------------------

	public function testDeleteItemDeletesAndWritesCardChangeRow(): void {
		$board = $this->board();
		$item = $this->item(50, 'I');
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->itemMapper->expects(self::once())->method('delete')->with($item);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$this->service->deleteItem(50, 'alice');
	}

	public function testDeleteItemAssertsActorEditPermission(): void {
		$board = $this->board();
		$item = $this->item(50, 'I');
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->itemMapper->expects(self::never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->deleteItem(50, 'mallory');
	}
}

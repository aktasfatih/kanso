<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChecklistItem;
use OCA\Kanso\Db\ChecklistItemMapper;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\ChecklistService;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotificationService;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SortKeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChecklistServiceTest extends TestCase {
	private ChecklistItemMapper&MockObject $itemMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private IDBConnection&MockObject $db;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private BoardAccess&MockObject $boardAccess;
	private NotificationService&MockObject $notificationService;
	private ChecklistService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->itemMapper = $this->createMock(ChecklistItemMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		// A real SortKeyService - the fractional-key maths is deterministic and
		// central to reorder behaviour, so exercise it rather than mock it.
		$this->service = $this->makeService($this->visibilityGuard);
	}

	/**
	 * Builds the service; a test that needs per-uid visibility answers passes
	 * its own guard mock (the setUp guard is stubbed to a blanket true).
	 */
	private function makeService(CardVisibilityGuard $guard): ChecklistService {
		return new ChecklistService(
			$this->itemMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			new SortKeyService(),
			$this->db,
			$guard,
			$this->boardAccess,
			$this->notificationService,
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
			->method('recordChange')
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->addItem(9, 'Second', 'alice');
	}

	public function testAddItemRejectsEmptyTitle(): void {
		$this->expectCardLoaded();
		$this->itemMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
			->method('recordChange')
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->updateItem(50, '  new title  ', null, 'alice');
	}

	public function testUpdateItemNoOpWritesNoChangeRow(): void {
		$item = $this->item(50, 'I', 'todo', true);
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->itemMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
			->method('recordChange')
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

	// ---- rich steps (#3745): done_at stamp --------------------------------

	public function testCompletingAnItemStampsDoneAt(): void {
		$item = $this->item(50, 'I', 'todo', false);
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$before = time();
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i) use ($before): ChecklistItem {
				self::assertTrue($i->getDone());
				self::assertNotNull($i->getDoneAt());
				self::assertGreaterThanOrEqual($before, $i->getDoneAt());
				return $i;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->updateItem(50, null, true, 'alice');
	}

	public function testUncheckingAnItemClearsDoneAt(): void {
		$item = $this->item(50, 'I', 'todo', true);
		$item->setDoneAt(1234);
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertFalse($i->getDone());
				self::assertNull($i->getDoneAt());
				return $i;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->updateItem(50, null, false, 'alice');
	}

	public function testRenameAloneLeavesDoneAtUntouched(): void {
		$item = $this->item(50, 'I', 'old', true);
		$item->setDoneAt(1234);
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertSame(1234, $i->getDoneAt());
				return $i;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->updateItem(50, 'renamed', null, 'alice');
	}

	// ---- rich steps (#3745): assignItem / unassignItem --------------------

	public function testAssignItemFreezesRoleStampsAndNotifies(): void {
		$board = $this->expectItemLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_EDIT);
		$this->permissionService->method('getPermissions')
			->with($board, 'client')
			->willReturn(PermissionService::PERMISSION_READ);
		// The role is FROZEN from the resolver at assign time: an external
		// member freezes 'external' (the epic-5 acceptance criterion).
		$this->boardAccess->expects(self::once())
			->method('contextFor')
			->with($board, 'client')
			->willReturn(ViewerContext::forMember('client', 1, ViewerContext::ROLE_EXTERNAL, false));
		$before = time();
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i) use ($before): ChecklistItem {
				self::assertSame('client', $i->getAssignedUser());
				self::assertSame(ViewerContext::ROLE_EXTERNAL, $i->getAssignedRole());
				self::assertGreaterThanOrEqual($before, $i->getAssignedAt());
				return $i;
			});
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('notifyStepAssigned')
			->with(50, 9, 'client', 'alice');
		$this->notificationService->expects(self::never())->method('dismissStepAssigned');

		$saved = $this->service->assignItem(50, 'client', 'alice');
		self::assertTrue($saved->waitsOnExternal(), 'an open external-assigned step waits on the client side');
	}

	public function testAssignItemRejectsParticipantWithoutBoardRead(): void {
		$this->expectItemLoaded();
		$this->permissionService->method('getPermissions')->willReturn(0);
		$this->itemMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');
		$this->notificationService->expects(self::never())->method('notifyStepAssigned');

		$this->expectException(InvalidInputException::class);
		$this->service->assignItem(50, 'stranger', 'alice');
	}

	public function testAssignItemRejectsParticipantWhoCannotSeeTheCard(): void {
		// Per-uid visibility: the actor sees the card, the would-be assignee
		// does not (e.g. an internal-side card assigned to an external member).
		$guard = $this->createMock(CardVisibilityGuard::class);
		$guard->method('isVisible')
			->willReturnCallback(static fn (Board $b, Card $c, string $uid): bool => $uid !== 'client');
		$service = $this->makeService($guard);

		$this->expectItemLoaded();
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->itemMapper->expects(self::never())->method('update');
		$this->notificationService->expects(self::never())->method('notifyStepAssigned');

		$this->expectException(InvalidInputException::class);
		$service->assignItem(50, 'client', 'alice');
	}

	public function testAssignItemIsIdempotentForTheSameAssignee(): void {
		$item = $this->item(50, 'I');
		$item->setAssignedUser('client');
		$item->setAssignedRole(ViewerContext::ROLE_EXTERNAL);
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->itemMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');
		$this->notificationService->expects(self::never())->method('notifyStepAssigned');

		self::assertSame($item, $this->service->assignItem(50, 'client', 'alice'));
	}

	public function testReassignReplacesAssigneeAndDismissesThePreviousBell(): void {
		$this->expectItemLoaded(function (ChecklistItem $item): void {
			$item->setAssignedUser('bob');
			$item->setAssignedRole(ViewerContext::ROLE_INTERNAL);
			$item->setAssignedAt(1000);
		});
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$this->boardAccess->method('contextFor')
			->willReturn(ViewerContext::forMember('client', 1, ViewerContext::ROLE_EXTERNAL, false));
		$this->itemMapper->method('update')->willReturnCallback(static fn (ChecklistItem $i): ChecklistItem => $i);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('dismissStepAssigned')
			->with(50, 'bob');
		$this->notificationService->expects(self::once())
			->method('notifyStepAssigned')
			->with(50, 9, 'client', 'alice');

		$saved = $this->service->assignItem(50, 'client', 'alice');
		self::assertSame('client', $saved->getAssignedUser());
		self::assertSame(ViewerContext::ROLE_EXTERNAL, $saved->getAssignedRole());
	}

	public function testAssignItemAssertsActorEditPermission(): void {
		$board = $this->expectItemLoaded();
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->itemMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->assignItem(50, 'client', 'mallory');
	}

	public function testAssignItemOnHiddenCardBehavesLikeMissing(): void {
		// The visibility gate (#3743): a card hidden from the ACTOR is a 404,
		// never a 403 - assign is card-addressed like every other write.
		$this->expectItemLoaded();
		$this->visibilityGuard->expects(self::once())
			->method('assertVisible')
			->willThrowException(new DoesNotExistException('Card 9 does not exist'));
		$this->itemMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service->assignItem(50, 'client', 'alice');
	}

	public function testUnassignItemClearsAssignmentAndDismissesTheBell(): void {
		$this->expectItemLoaded(function (ChecklistItem $item): void {
			$item->setAssignedUser('client');
			$item->setAssignedRole(ViewerContext::ROLE_EXTERNAL);
			$item->setAssignedAt(1000);
		});
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertNull($i->getAssignedUser());
				self::assertNull($i->getAssignedRole());
				self::assertNull($i->getAssignedAt());
				return $i;
			});
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());
		$this->notificationService->expects(self::once())
			->method('dismissStepAssigned')
			->with(50, 'client');

		$this->service->unassignItem(50, 'alice');
	}

	public function testUnassignItemIsANoOpWhenUnassigned(): void {
		$this->expectItemLoaded();
		$this->itemMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');
		$this->notificationService->expects(self::never())->method('dismissStepAssigned');

		$this->service->unassignItem(50, 'alice');
	}

	// ---- rich steps (#3745): setItemDue -----------------------------------

	public function testSetItemDueParsesIsoToUtcAndWritesChangeRow(): void {
		$this->expectItemLoaded();
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertNotNull($i->getDueDate());
				self::assertSame('2026-08-14T18:00:00+00:00', $i->getDueDate()->format(\DateTimeInterface::ATOM));
				return $i;
			});
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$this->service->setItemDue(50, '2026-08-14T18:00:00Z', 'alice');
	}

	public function testSetItemDueNullClearsTheDate(): void {
		$this->expectItemLoaded(function (ChecklistItem $item): void {
			$item->setDueDate(new \DateTime('2026-08-14T18:00:00Z'));
		});
		$this->itemMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (ChecklistItem $i): ChecklistItem {
				self::assertNull($i->getDueDate());
				return $i;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->setItemDue(50, null, 'alice');
	}

	public function testSetItemDueRejectsGarbage(): void {
		$this->expectItemLoaded();
		$this->itemMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->setItemDue(50, 'next tuesday', 'alice');
	}

	public function testSetItemDueSameInstantIsANoOp(): void {
		$this->expectItemLoaded(function (ChecklistItem $item): void {
			$item->setDueDate(new \DateTime('2026-08-14T18:00:00Z'));
		});
		$this->itemMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->service->setItemDue(50, '2026-08-14T18:00:00Z', 'alice');
	}

	public function testAddItemSeedsCloneDueDateButNeverAssignee(): void {
		// The clone paths (copy / template / import) hand addItem the source's
		// due date; assignee + done_at have no addItem parameter AT ALL, which
		// is the "drop on clone" policy enforced by construction.
		$this->expectCardLoaded();
		$this->itemMapper->method('findLastByCard')->willReturn(null);
		$due = new \DateTime('2026-08-14T18:00:00Z');
		$this->itemMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function (ChecklistItem $i) use ($due): ChecklistItem {
				self::assertSame($due->getTimestamp(), $i->getDueDate()?->getTimestamp());
				self::assertTrue($i->getDone());
				self::assertNull($i->getAssignedUser());
				self::assertNull($i->getAssignedRole());
				self::assertNull($i->getAssignedAt());
				self::assertNull($i->getDoneAt());
				return $i;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->addItem(9, 'Cloned step', 'alice', true, $due);
	}

	/**
	 * Wires the mappers for an item-addressed call (item 50 on card 9, board 1)
	 * and returns the board. $mutateItem tweaks the item before it is served.
	 *
	 * @param callable(ChecklistItem):void|null $mutateItem
	 */
	private function expectItemLoaded(?callable $mutateItem = null): Board {
		$board = $this->board();
		$item = $this->item(50, 'I');
		if ($mutateItem !== null) {
			$mutateItem($item);
		}
		$this->itemMapper->method('find')->with(50)->willReturn($item);
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}
}

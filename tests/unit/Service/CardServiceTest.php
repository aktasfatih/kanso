<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\AutomationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\SortKeyService;
use OCA\Kanso\Service\SubscriptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardServiceTest extends TestCase {
	private CardMapper&MockObject $cardMapper;
	private StackMapper&MockObject $stackMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private CardReviewMapper&MockObject $cardReviewMapper;
	private IDBConnection&MockObject $db;
	private SubscriptionService&MockObject $subscriptionService;
	private AutomationService&MockObject $automationService;
	private CardService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->cardReviewMapper = $this->createMock(CardReviewMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->subscriptionService = $this->createMock(SubscriptionService::class);
		$this->automationService = $this->createMock(AutomationService::class);
		$this->service = new CardService(
			$this->cardMapper,
			$this->stackMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			new SortKeyService(),
			$this->cardReviewMapper,
			$this->db,
			$this->subscriptionService,
			$this->automationService
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function stack(int $id = 5, int $boardId = 1, int $role = Stack::ROLE_NONE): Stack {
		$stack = new Stack();
		$stack->setId($id);
		$stack->setBoardId($boardId);
		$stack->setTitle('Existing stack');
		$stack->setSortKey('I');
		$stack->setRole($role);
		$stack->setDeletedAt(0);
		return $stack;
	}

	private function card(int $id = 9, int $stackId = 5, int $boardId = 1, string $sortKey = 'I'): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId($stackId);
		$card->setTitle('Existing card');
		$card->setSortKey($sortKey);
		$card->setDoneAt(0);
		$card->setStartedAt(0);
		$card->setArchived(false);
		$card->setOwner('alice');
		$card->setDeletedAt(0);
		return $card;
	}

	/**
	 * A mocked NC-portable unique-constraint violation, as the mapper surfaces
	 * one (see AclServiceTest/AssigneeServiceTest for the same shape).
	 */
	private function uniqueViolation(): \OCP\DB\Exception&MockObject {
		$e = $this->createMock(\OCP\DB\Exception::class);
		$e->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		return $e;
	}

	// ---- create -----------------------------------------------------------

	public function testCreateOnEmptyStackUsesInitialSortKey(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				self::assertSame('I', $card->getSortKey());
				self::assertSame('A card', $card->getTitle());
				self::assertSame(1, $card->getBoardId());
				self::assertSame(5, $card->getStackId());
				self::assertSame('alice', $card->getOwner());
				self::assertSame(0, $card->getDoneAt());
				self::assertFalse($card->getArchived());
				self::assertGreaterThan(0, $card->getCreatedAt());
				self::assertGreaterThan(0, $card->getLastModified());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_CREATE,
				'alice'
			)
			->willReturn(new Change());

		$card = $this->service->create(5, 'A card', 'alice');
		self::assertSame(9, $card->getId());
	}

	public function testCreateAppendsAfterLastCard(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)
			->willReturn($this->card(8, 5, 1, 'J'));
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				// after('J') === 'K'
				self::assertSame('K', $card->getSortKey());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice');
	}

	public function testCreateRejectsEmptyTitle(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->create(5, '   ', 'alice');
	}

	public function testCreateRejectsOverlongTitle(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(5, str_repeat('x', 101), 'alice');
	}

	public function testCreateAssertsEditPermission(): void {
		$board = $this->board();
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->create(5, 'A card', 'bob');
	}

	public function testCreateRejectsDeletedStack(): void {
		$stack = $this->stack();
		$stack->setDeletedAt(1234);
		$this->stackMapper->method('find')->with(5)->willReturn($stack);
		$this->cardMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->service->create(5, 'A card', 'alice');
	}

	public function testCreateRetriesOnceOnSortKeyConflictThenSucceeds(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// The neighbour shifts between attempts: 'J' → after 'K' (collides),
		// then 'K' → after 'L' (succeeds).
		$this->cardMapper->method('findLastInStack')->with(5)
			->willReturnOnConsecutiveCalls(
				$this->card(8, 5, 1, 'J'),
				$this->card(8, 5, 1, 'K'),
			);
		$attempt = 0;
		$this->cardMapper->expects(self::exactly(2))
			->method('insert')
			->willReturnCallback(function (Card $card) use (&$attempt): Card {
				$attempt++;
				if ($attempt === 1) {
					self::assertSame('K', $card->getSortKey());
					throw $this->uniqueViolation();
				}
				self::assertSame('L', $card->getSortKey());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_CREATE, 'alice')
			->willReturn(new Change());

		$card = $this->service->create(5, 'A card', 'alice');
		self::assertSame(9, $card->getId());
		self::assertSame('L', $card->getSortKey());
	}

	public function testCreateThrowsConflictAfterRetryExhausted(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)
			->willReturn($this->card(8, 5, 1, 'J'));
		$this->cardMapper->expects(self::exactly(2))
			->method('insert')
			->willReturnCallback(fn (Card $card): Card => throw $this->uniqueViolation());
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(\OverflowException::class);
		$this->service->create(5, 'A card', 'alice');
	}

	public function testCreateInDoneRoleStackStampsDone(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5, 1, Stack::ROLE_DONE));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				// Created directly in a done-role stack → stamped done on create.
				self::assertGreaterThan(0, $card->getDoneAt());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice');
	}

	// ---- find -------------------------------------------------------------

	public function testFindAssertsReadPermissionAndReturnsCard(): void {
		$card = $this->card();
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_READ);

		self::assertSame($card, $this->service->find(9, 'alice'));
	}

	public function testFindThrowsForSoftDeletedCard(): void {
		$card = $this->card();
		$card->setDeletedAt(1234);
		$this->cardMapper->method('find')->with(9)->willReturn($card);

		$this->expectException(DoesNotExistException::class);
		$this->service->find(9, 'alice');
	}

	// ---- update -----------------------------------------------------------

	public function testUpdateAppliesFieldsAndWritesChangeRow(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_UPDATE,
				'alice'
			)
			->willReturn(new Change());

		$updated = $this->service->update(9, 'Renamed', 'A description', null, null, true, 'alice');
		self::assertSame('Renamed', $updated->getTitle());
		self::assertSame('A description', $updated->getDescription());
		self::assertTrue($updated->getArchived());
		self::assertGreaterThan(0, $updated->getLastModified());
	}

	public function testUpdateLeavesFieldsUnchangedOnNull(): void {
		$card = $this->card();
		$card->setDescription('Keep me');
		$card->setDuedate(new \DateTime('2026-08-01T10:00:00+00:00'));
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, null, null, 'alice');
		self::assertSame('Existing card', $updated->getTitle());
		self::assertSame('Keep me', $updated->getDescription());
		self::assertNotNull($updated->getDuedate());
		self::assertFalse($updated->getArchived());
	}

	public function testUpdateClearsDuedateOnEmptyString(): void {
		$card = $this->card();
		$card->setDuedate(new \DateTime('2026-08-01T10:00:00+00:00'));
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, '', null, null, 'alice');
		self::assertNull($updated->getDuedate());
	}

	public function testUpdateSetsAndClearsStartDate(): void {
		$card = $this->card();
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// Set (positional: …, uid, priority, startDate).
		$set = $this->service->update(9, null, null, null, null, null, 'alice', null, '2026-08-01T00:00:00+00:00');
		self::assertInstanceOf(\DateTime::class, $set->getStartDate());

		// Clear with an empty string.
		$cleared = $this->service->update(9, null, null, null, null, null, 'alice', null, '');
		self::assertNull($cleared->getStartDate());
	}

	public function testUpdateSetsEstimateFromBoardScale(): void {
		$board = $this->board();
		$board->setEstimateScale('fibonacci');
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// positional: …, uid, priority, startDate, status, estimate
		$updated = $this->service->update(9, null, null, null, null, null, 'alice', null, null, null, '8');
		self::assertSame('8', $updated->getEstimate());
	}

	public function testUpdateRejectsOffScaleEstimate(): void {
		$board = $this->board();
		$board->setEstimateScale('fibonacci');
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);

		$this->expectException(InvalidInputException::class);
		// 4 is not a fibonacci token.
		$this->service->update(9, null, null, null, null, null, 'alice', null, null, null, '4');
	}

	public function testUpdateRejectsAnyEstimateWhenScaleIsNone(): void {
		$board = $this->board();
		$board->setEstimateScale('none');
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);

		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, null, null, null, 'alice', null, null, null, '3');
	}

	public function testUpdateClearsEstimateOnEmptyString(): void {
		$card = $this->card();
		$card->setEstimate('5');
		$board = $this->board();
		$board->setEstimateScale('fibonacci');
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, null, null, 'alice', null, null, null, '');
		self::assertNull($updated->getEstimate());
	}

	public function testUpdateParsesAtomDuedateAndNormalizesToUtc(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, '2026-08-01T10:00:00+02:00', null, null, 'alice');
		self::assertSame(
			'2026-08-01T08:00:00+00:00',
			$updated->getDuedate()?->format(\DateTimeInterface::ATOM)
		);
	}

	public function testUpdateParsesMillisecondDuedate(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// JS Date.toISOString() shape: milliseconds + 'Z'.
		$updated = $this->service->update(9, null, null, '2026-08-01T10:00:00.000Z', null, null, 'alice');
		self::assertSame(
			'2026-08-01T10:00:00+00:00',
			$updated->getDuedate()?->format(\DateTimeInterface::ATOM)
		);
	}

	public function testUpdateRejectsInvalidDuedate(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, 'tomorrow', null, null, 'alice');
	}

	public function testUpdateRejectsRolledOverDuedate(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		// createFromFormat would silently roll February 30th to March 2nd.
		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, '2026-02-30T12:00:00Z', null, null, 'alice');
	}

	public function testUpdateDoneStampsDoneAt(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, true, null, 'alice');
		self::assertGreaterThan(0, $updated->getDoneAt());
	}

	public function testUpdateDoneIsIdempotent(): void {
		$card = $this->card();
		$card->setDoneAt(12345);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, true, null, 'alice');
		self::assertSame(12345, $updated->getDoneAt());
	}

	public function testUpdateUndoneClearsDoneAt(): void {
		$card = $this->card();
		$card->setDoneAt(12345);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, false, null, 'alice');
		self::assertSame(0, $updated->getDoneAt());
	}

	public function testUpdateAssertsEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->update(9, 'Renamed', null, null, null, null, 'bob');
	}

	// ---- delete -----------------------------------------------------------

	public function testDeleteSoftDeletesAndWritesChangeRow(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())
			->method('update')
			->with(self::callback(static fn (Card $c): bool => $c->getDeletedAt() > 0))
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_DELETE,
				'alice'
			)
			->willReturn(new Change());

		$this->service->delete(9, 'alice');
	}

	// ---- move -------------------------------------------------------------

	public function testMoveToTopUsesBeforeFirstKey(): void {
		$this->cardMapper->method('find')->with(9)
			->willReturn($this->card(9, 5, 1, 'K'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)
			->willReturn($this->card(10, 6, 1, 'I'));
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_MOVE,
				'alice'
			)
			->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		// before('I') === 'H'
		self::assertSame('H', $moved->getSortKey());
		self::assertSame(6, $moved->getStackId());
	}

	public function testMoveToEmptyStackUsesInitialKey(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame('I', $moved->getSortKey());
		self::assertSame(6, $moved->getStackId());
	}

	public function testMoveBetweenCardsUsesMidpointKeyInsideTransaction(): void {
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $this->card(9, 5, 1, 'V'),
			10 => $this->card(10, 6, 1, 'I'),
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findNextInStack')->with(6, 'I')
			->willReturn($this->card(11, 6, 1, 'J'));
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->willReturn(new Change());
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$moved = $this->service->move(9, 6, 10, 'alice');
		// between('I', 'J') === 'II'
		self::assertSame('II', $moved->getSortKey());
		self::assertSame(6, $moved->getStackId());
	}

	public function testMoveAfterLastCardUsesAfterKey(): void {
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $this->card(9, 5, 1, 'V'),
			10 => $this->card(10, 6, 1, 'J'),
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findNextInStack')->with(6, 'J')->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, 10, 'alice');
		// after('J') === 'K'
		self::assertSame('K', $moved->getSortKey());
	}

	public function testSequentialBetweenMovesProduceDistinctOrderedKeys(): void {
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $this->card(9, 5, 1, 'V'),
			10 => $this->card(10, 6, 1, 'I'),
			12 => $this->card(12, 5, 1, 'W'),
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		// First move sees 'J' as the next card; the second one sees the card
		// just moved to 'II'.
		$this->cardMapper->method('findNextInStack')->with(6, 'I')
			->willReturnOnConsecutiveCalls(
				$this->card(11, 6, 1, 'J'),
				$this->card(9, 6, 1, 'II')
			);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$firstKey = $this->service->move(9, 6, 10, 'alice')->getSortKey();
		$secondKey = $this->service->move(12, 6, 10, 'alice')->getSortKey();

		self::assertSame('II', $firstKey);
		self::assertSame('I9', $secondKey);
		self::assertNotSame($firstKey, $secondKey);
		// Both sort after the anchor 'I'; the second insertion lands between.
		self::assertLessThan(0, strcmp('I', $secondKey));
		self::assertLessThan(0, strcmp($secondKey, $firstKey));
	}

	public function testMoveRejectsCrossBoardTarget(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->with(6)->willReturn($this->stack(6, 2));
		$this->db->expects(self::never())->method('beginTransaction');
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(InvalidInputException::class);
		$this->service->move(9, 6, null, 'alice');
	}

	public function testMoveRejectsAfterCardInAnotherStack(): void {
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $this->card(9, 5, 1, 'V'),
			10 => $this->card(10, 7, 1, 'I'),
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->db->expects(self::never())->method('beginTransaction');
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->move(9, 6, 10, 'alice');
	}

	public function testMoveRejectsAfterCardSelf(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->db->expects(self::never())->method('beginTransaction');
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->move(9, 5, 9, 'alice');
	}

	public function testMoveRejectsDeletedAfterCard(): void {
		$deleted = $this->card(10, 6, 1, 'I');
		$deleted->setDeletedAt(1234);
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $this->card(9, 5, 1, 'V'),
			10 => $deleted,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->move(9, 6, 10, 'alice');
	}

	public function testMoveRejectsMissingAfterCard(): void {
		$this->cardMapper->method('find')->willReturnCallback(function (int $id): Card {
			if ($id === 9) {
				return $this->card(9, 5, 1, 'V');
			}
			throw new DoesNotExistException('gone');
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->move(9, 6, 10, 'alice');
	}

	public function testMoveAssertsEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->db->expects(self::never())->method('beginTransaction');
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->move(9, 6, null, 'bob');
	}

	public function testMoveRollsBackTransactionOnMapperFailure(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')
			->willThrowException(new \RuntimeException('db gone'));
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(\RuntimeException::class);
		$this->service->move(9, 6, null, 'alice');
	}

	// ---- move sort-key conflict retry -------------------------------------

	public function testMoveRetriesOnceOnSortKeyConflictThenSucceeds(): void {
		$this->cardMapper->method('find')->willReturnCallback(
			fn (int $id): Card => $this->card(9, 5, 1, 'V')
		);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$attempt = 0;
		$this->cardMapper->expects(self::exactly(2))
			->method('update')
			->willReturnCallback(function (Card $card) use (&$attempt): Card {
				$attempt++;
				if ($attempt === 1) {
					throw $this->uniqueViolation();
				}
				return $card;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());
		$this->db->expects(self::exactly(2))->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::once())->method('commit');

		$moved = $this->service->move(9, 6, null, 'alice');
		// findFirstInStack === null → initial() === 'I' on the successful retry.
		self::assertSame('I', $moved->getSortKey());
		self::assertSame(6, $moved->getStackId());
	}

	public function testMoveThrowsConflictAfterRetryExhausted(): void {
		$this->cardMapper->method('find')->willReturnCallback(
			fn (int $id): Card => $this->card(9, 5, 1, 'V')
		);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->expects(self::exactly(2))
			->method('update')
			->willReturnCallback(fn (Card $card): Card => throw $this->uniqueViolation());
		$this->changeNotifier->expects(self::never())->method('notify');
		$this->db->expects(self::exactly(2))->method('beginTransaction');
		$this->db->expects(self::exactly(2))->method('rollBack');
		$this->db->expects(self::never())->method('commit');

		$this->expectException(\OverflowException::class);
		$this->service->move(9, 6, null, 'alice');
	}

	public function testMoveDoesNotRetryOnNonUniqueDbError(): void {
		$this->cardMapper->method('find')->willReturnCallback(
			fn (int $id): Card => $this->card(9, 5, 1, 'V')
		);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$dbError = $this->createMock(\OCP\DB\Exception::class);
		$dbError->method('getReason')->willReturn(\OCP\DB\Exception::REASON_CONNECTION_LOST);
		$this->cardMapper->expects(self::once())
			->method('update')
			->willThrowException($dbError);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(\OCP\DB\Exception::class);
		$this->service->move(9, 6, null, 'alice');
	}

	// ---- move review gate -------------------------------------------------

	public function testMoveFromReviewToDoneBlockedByUnapprovedReviews(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(9, 5, 1, 'V'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5, 1, Stack::ROLE_REVIEW),
			6 => $this->stack(6, 1, Stack::ROLE_DONE),
		});
		$this->cardReviewMapper->method('hasUnapprovedReviews')->with(9)->willReturn(true);
		$this->db->expects(self::never())->method('beginTransaction');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->expectException(NotPermittedException::class);
		$this->service->move(9, 6, null, 'alice');
	}

	public function testMoveFromReviewToDoneAllowedWhenAllApproved(): void {
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => $this->card(9, 5, 1, 'V'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5, 1, Stack::ROLE_REVIEW),
			6 => $this->stack(6, 1, Stack::ROLE_DONE),
		});
		$this->cardReviewMapper->method('hasUnapprovedReviews')->with(9)->willReturn(false);
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame(6, $moved->getStackId());
		// Into a done-role stack, done-automation still stamps done_at.
		self::assertGreaterThan(0, $moved->getDoneAt());
	}

	public function testMoveIntoDoneFromNonReviewStackIgnoresReviews(): void {
		// The gate only fires when leaving a review-role stack — unapproved
		// reviews do not block a move from any other role.
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => $this->card(9, 5, 1, 'V'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5, 1, Stack::ROLE_IN_PROGRESS),
			6 => $this->stack(6, 1, Stack::ROLE_DONE),
		});
		$this->cardReviewMapper->method('hasUnapprovedReviews')->willReturn(true);
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame(6, $moved->getStackId());
	}

	// ---- auto-complete parent (all children done) -------------------------

	public function testUpdatingLastChildToDoneStampsParentDone(): void {
		$child = $this->card(9, 5, 1, 'I');
		$child->setParentCardId(100);
		$parent = $this->card(100, 6, 1, 'K');
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			100 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$doneA = $this->card(9, 5, 1, 'I');
		$doneA->setDoneAt(1000);
		$doneB = $this->card(11, 5, 1, 'J');
		$doneB->setDoneAt(1000);
		$this->cardMapper->method('findChildren')->with(100)->willReturn([$doneA, $doneB]);
		$updated = [];
		$this->cardMapper->method('update')->willReturnCallback(function (Card $c) use (&$updated): Card {
			$updated[] = $c->getId();
			return $c;
		});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->update(9, null, null, null, true, null, 'alice');

		self::assertContains(100, $updated, 'parent should be stamped done');
		self::assertGreaterThan(0, $parent->getDoneAt());
	}

	public function testUpdatingChildToDoneWithOpenSiblingLeavesParent(): void {
		$child = $this->card(9, 5, 1, 'I');
		$child->setParentCardId(100);
		$parent = $this->card(100, 6, 1, 'K');
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			100 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$doneChild = $this->card(9, 5, 1, 'I');
		$doneChild->setDoneAt(1000);
		$openSibling = $this->card(11, 5, 1, 'J');
		$this->cardMapper->method('findChildren')->with(100)->willReturn([$doneChild, $openSibling]);
		$updated = [];
		$this->cardMapper->method('update')->willReturnCallback(function (Card $c) use (&$updated): Card {
			$updated[] = $c->getId();
			return $c;
		});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->update(9, null, null, null, true, null, 'alice');

		self::assertNotContains(100, $updated, 'parent must stay open');
		self::assertSame(0, $parent->getDoneAt());
	}

	public function testArchivedSiblingCountsAsResolvedForParentCompletion(): void {
		$child = $this->card(9, 5, 1, 'I');
		$child->setParentCardId(100);
		$parent = $this->card(100, 6, 1, 'K');
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			100 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$doneChild = $this->card(9, 5, 1, 'I');
		$doneChild->setDoneAt(1000);
		$archivedSibling = $this->card(11, 5, 1, 'J');
		$archivedSibling->setArchived(true);
		$this->cardMapper->method('findChildren')->with(100)->willReturn([$doneChild, $archivedSibling]);
		$updated = [];
		$this->cardMapper->method('update')->willReturnCallback(function (Card $c) use (&$updated): Card {
			$updated[] = $c->getId();
			return $c;
		});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->update(9, null, null, null, true, null, 'alice');

		self::assertContains(100, $updated, 'archived sibling counts as resolved');
	}

	public function testParentAlreadyDoneIsNotReStamped(): void {
		$child = $this->card(9, 5, 1, 'I');
		$child->setParentCardId(100);
		$parent = $this->card(100, 6, 1, 'K');
		$parent->setDoneAt(500);
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			100 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('findChildren');
		$updated = [];
		$this->cardMapper->method('update')->willReturnCallback(function (Card $c) use (&$updated): Card {
			$updated[] = $c->getId();
			return $c;
		});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->update(9, null, null, null, true, null, 'alice');

		self::assertNotContains(100, $updated, 'a human-done parent is never re-stamped');
		self::assertSame(500, $parent->getDoneAt());
	}

	// ---- move done-automation --------------------------------------------

	public function testMoveIntoDoneStackStampsDoneAtInsideTransaction(): void {
		// Card 9 lives in a plain stack (5); target stack 6 has the done role.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(9, 5, 1, 'V'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6, 1, Stack::ROLE_DONE),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('update')
			->with(self::callback(static fn (Card $c): bool => $c->getDoneAt() > 0))
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_MOVE, 'alice')
			->willReturn(new Change());
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertGreaterThan(0, $moved->getDoneAt());
	}

	public function testMoveIntoDoneStackLeavesAlreadyDoneCardStamp(): void {
		$card = $this->card(9, 5, 1, 'V');
		$card->setDoneAt(12345);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6, 1, Stack::ROLE_DONE),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame(12345, $moved->getDoneAt());
	}

	public function testMoveIntoBacklogStackClearsStatus(): void {
		// A column's role IS its status: a done card dragged into a backlog-role
		// column (5, done) → (6, backlog) is reset to "not started" — both
		// timestamps cleared.
		$card = $this->card(9, 5, 1, 'V');
		$card->setDoneAt(12345);
		$card->setStartedAt(500);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5, 1, Stack::ROLE_DONE),
			6 => $this->stack(6, 1, Stack::ROLE_BACKLOG),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('update')
			->with(self::callback(static fn (Card $c): bool => $c->getDoneAt() === 0 && $c->getStartedAt() === 0))
			->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame(0, $moved->getDoneAt());
		self::assertSame(0, $moved->getStartedAt());
	}

	public function testMoveOutOfDoneToRolelessStackKeepsStatus(): void {
		// A role-less column carries no status, so moving a done card into one
		// leaves its done stamp untouched.
		$card = $this->card(9, 5, 1, 'V');
		$card->setDoneAt(12345);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5, 1, Stack::ROLE_DONE),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame(12345, $moved->getDoneAt());
	}

	// ---- status (started) automation + direct set -------------------------

	public function testMoveIntoInProgressStackStampsStarted(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(9, 5, 1, 'V'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6, 1, Stack::ROLE_IN_PROGRESS),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertGreaterThan(0, $moved->getStartedAt());
		self::assertSame(0, $moved->getDoneAt());
	}

	public function testMoveIntoInProgressStackReopensADoneCard(): void {
		// The column's role is its status: dragging a done card into an
		// in-progress column reopens it — done cleared, started stamped.
		$card = $this->card(9, 5, 1, 'V');
		$card->setDoneAt(12345);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6, 1, Stack::ROLE_IN_PROGRESS),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertGreaterThan(0, $moved->getStartedAt());
		self::assertSame(0, $moved->getDoneAt());
	}

	public function testUpdateStatusTransitions(): void {
		$card = $this->card();
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		// in_progress → started stamped, not done.
		$r = $this->service->update(9, null, null, null, null, null, 'alice', null, null, 'in_progress');
		self::assertGreaterThan(0, $r->getStartedAt());
		self::assertSame(0, $r->getDoneAt());

		// done → done stamped.
		$r = $this->service->update(9, null, null, null, null, null, 'alice', null, null, 'done');
		self::assertGreaterThan(0, $r->getDoneAt());

		// not_started → both cleared (moves the card BACKWARD).
		$r = $this->service->update(9, null, null, null, null, null, 'alice', null, null, 'not_started');
		self::assertSame(0, $r->getStartedAt());
		self::assertSame(0, $r->getDoneAt());
	}

	public function testUpdateStatusRejectsUnknown(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());

		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, null, null, null, 'alice', null, null, 'bogus');
	}

	public function testMoveBetweenNonDoneStacksLeavesDoneAtUntouched(): void {
		// Neither stack is done-role; a done card keeps its stamp.
		$card = $this->card(9, 5, 1, 'V');
		$card->setDoneAt(12345);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6),
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame(12345, $moved->getDoneAt());
	}

	// ---- setParent --------------------------------------------------------

	public function testSetParentLinksChildAndWritesCardChangeRow(): void {
		$child = $this->card(9, 5, 1);
		$parent = $this->card(20, 5, 1);
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			20 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'alice', PermissionService::PERMISSION_EDIT);
		$this->cardMapper->method('hasChildren')->with(9)->willReturn(false);
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Card $c): Card {
				self::assertSame(20, $c->getParentCardId());
				return $c;
			});
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$result = $this->service->setParent(9, 20, 'alice');
		self::assertSame(20, $result->getParentCardId());
	}

	public function testSetParentClearsParentAndWritesChangeRow(): void {
		$child = $this->card(9, 5, 1);
		$child->setParentCardId(20);
		$this->cardMapper->method('find')->with(9)->willReturn($child);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Card $c): Card {
				self::assertNull($c->getParentCardId());
				return $c;
			});
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$this->service->setParent(9, null, 'alice');
	}

	public function testSetParentClearingAlreadyUnparentedIsNoOp(): void {
		$child = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($child);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->setParent(9, null, 'alice');
	}

	public function testSetParentToSameParentIsNoOp(): void {
		$child = $this->card(9, 5, 1);
		$child->setParentCardId(20);
		$parent = $this->card(20, 5, 1);
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			20 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('hasChildren')->with(9)->willReturn(false);
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('notify');

		$this->service->setParent(9, 20, 'alice');
	}

	public function testSetParentRejectsSelfParent(): void {
		$child = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($child);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->setParent(9, 9, 'alice');
	}

	public function testSetParentRejectsParentOnAnotherBoard(): void {
		$child = $this->card(9, 5, 1);
		$parent = $this->card(20, 8, 2); // board 2
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			20 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('same board');
		$this->service->setParent(9, 20, 'alice');
	}

	public function testSetParentRejectsGrandparentDepth(): void {
		$child = $this->card(9, 5, 1);
		$parent = $this->card(20, 5, 1);
		$parent->setParentCardId(30); // the chosen parent is itself a child
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			20 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('one level');
		$this->service->setParent(9, 20, 'alice');
	}

	public function testSetParentRejectsWhenChildAlreadyHasChildren(): void {
		$child = $this->card(9, 5, 1);
		$parent = $this->card(20, 5, 1);
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $child,
			20 => $parent,
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('hasChildren')->with(9)->willReturn(true);
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->expectExceptionMessage('has children');
		$this->service->setParent(9, 20, 'alice');
	}

	public function testSetParentRejectsMissingParentAsInvalidInput(): void {
		$child = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->willReturnCallback(function (int $id): Card {
			if ($id === 9) {
				return $this->card(9, 5, 1);
			}
			throw new DoesNotExistException('gone');
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->setParent(9, 999, 'alice');
	}

	public function testSetParentAssertsActorEditPermission(): void {
		$child = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($child);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with(self::anything(), 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->setParent(9, 20, 'mallory');
	}

	public function testSetParentRejectsDeletedCard(): void {
		$child = $this->card(9, 5, 1);
		$child->setDeletedAt(999);
		$this->cardMapper->method('find')->with(9)->willReturn($child);
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->service->setParent(9, 20, 'alice');
	}

	// ---- delete detaches children -----------------------------------------

	public function testDeleteDetachesChildrenBeforeSoftDeletingParent(): void {
		$parent = $this->card(9, 5, 1);
		$childA = $this->card(11, 5, 1);
		$childA->setParentCardId(9);
		$childB = $this->card(12, 5, 1);
		$childB->setParentCardId(9);

		$this->cardMapper->method('find')->with(9)->willReturn($parent);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findChildren')->with(9)->willReturn([$childA, $childB]);

		$updated = [];
		$this->cardMapper->method('update')->willReturnCallback(function (Card $c) use (&$updated): Card {
			$updated[$c->getId()] = $c;
			return $c;
		});
		$this->changeNotifier->method('notify')->willReturn(new Change());

		$this->service->delete(9, 'alice');

		// Both children were detached...
		self::assertNull($updated[11]->getParentCardId());
		self::assertNull($updated[12]->getParentCardId());
		// ...and the parent itself was soft-deleted.
		self::assertGreaterThan(0, $updated[9]->getDeletedAt());
	}

	// ---- update priority --------------------------------------------------

	public function testUpdateSetsPriority(): void {
		$card = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())->method('notify')->willReturn(new Change());

		$result = $this->service->update(9, null, null, null, null, null, 'alice', 3);
		self::assertSame(3, $result->getPriority());
	}

	public function testUpdateRejectsPriorityAboveRange(): void {
		$card = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, null, null, null, 'alice', 5);
	}

	public function testUpdateRejectsNegativePriority(): void {
		$card = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, null, null, null, 'alice', -1);
	}
}

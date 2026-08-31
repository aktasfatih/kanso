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
use OCA\Kanso\Db\CardReviewMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\ChangeDetail;
use OCA\Kanso\Db\ChangeDetailMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\AutomationService;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\DescriptionConflictException;
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
	private \OCA\Kanso\Service\MentionService&MockObject $mentionService;
	private \OCA\Kanso\Service\LabelService&MockObject $labelService;
	private \OCA\Kanso\Service\ChecklistService&MockObject $checklistService;
	private \OCA\Kanso\Db\LabelMapper&MockObject $labelMapper;
	private \OCA\Kanso\Db\CardLabelMapper&MockObject $cardLabelMapper;
	private \OCA\Kanso\Db\ChecklistItemMapper&MockObject $checklistItemMapper;
	private \OCA\Kanso\Db\CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private \OCA\Kanso\Db\SubscriptionMapper&MockObject $subscriptionMapper;
	private BoardAccess&MockObject $boardAccess;
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private ChangeDetailMapper&MockObject $changeDetailMapper;
	/** The role the BoardAccess mock resolves creators to (#3741 freeze). */
	private string $resolvedRole = ViewerContext::ROLE_INTERNAL;
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
		$this->mentionService = $this->createMock(\OCA\Kanso\Service\MentionService::class);
		$this->labelService = $this->createMock(\OCA\Kanso\Service\LabelService::class);
		$this->checklistService = $this->createMock(\OCA\Kanso\Service\ChecklistService::class);
		$this->labelMapper = $this->createMock(\OCA\Kanso\Db\LabelMapper::class);
		$this->cardLabelMapper = $this->createMock(\OCA\Kanso\Db\CardLabelMapper::class);
		$this->checklistItemMapper = $this->createMock(\OCA\Kanso\Db\ChecklistItemMapper::class);
		$this->cardAssigneeMapper = $this->createMock(\OCA\Kanso\Db\CardAssigneeMapper::class);
		$this->subscriptionMapper = $this->createMock(\OCA\Kanso\Db\SubscriptionMapper::class);
		$this->boardAccess = $this->createMock(BoardAccess::class);
		// Membership for the creator_role freeze (#3741): every creator
		// resolves to $this->resolvedRole (internal by default; tests
		// exercising the external side flip the property).
		$this->resolvedRole = ViewerContext::ROLE_INTERNAL;
		$this->boardAccess->method('contextFor')->willReturnCallback(
			fn (Board $board, string $uid): ViewerContext
				=> ViewerContext::forMember($uid, $board->getId(), $this->resolvedRole, false),
		);
		// Visibility gate (#3743): every card is visible by default so the
		// pre-existing behavioral tests are unaffected; hidden-card tests
		// override assertVisible per test.
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->visibilityGuard->method('isVisible')->willReturn(true);
		$this->changeDetailMapper = $this->createMock(ChangeDetailMapper::class);
		// Default: any detail write returns a row (the typed return must not be null,
		// or a move/update inside the transaction would TypeError and roll back).
		// Tests asserting specific from/to values override this.
		$this->changeDetailMapper->method('insertDetail')->willReturn(new ChangeDetail());
		// Lazy container → RecurrenceService for the repeat re-arm on a date edit;
		// a no-op mock (no template card carries a rule in these tests).
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($this->createMock(\OCA\Kanso\Service\RecurrenceService::class));
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
			$this->automationService,
			$this->mentionService,
			$this->labelService,
			$this->checklistService,
			$this->labelMapper,
			$this->cardLabelMapper,
			$this->checklistItemMapper,
			$this->cardAssigneeMapper,
			$this->subscriptionMapper,
			$this->boardAccess,
			$this->visibilityGuard,
			$this->changeDetailMapper,
			$container
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
			->method('recordChange')
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

	public function testCreateStartsPublicWithFrozenCreatorRole(): void {
		// Visibility model (#3741): a new card is 'public' (default-open) and
		// carries the creator's resolved board side frozen on the INSERT.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				self::assertSame('public', $card->getVisibility());
				self::assertSame(ViewerContext::ROLE_INTERNAL, $card->getCreatorRole());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice');
	}

	public function testCreateFreezesExternalCreatorRole(): void {
		// An external (client-side) member's card freezes 'external' - the
		// symmetric half of the internal-visibility rule.
		$this->resolvedRole = ViewerContext::ROLE_EXTERNAL;
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				self::assertSame('public', $card->getVisibility());
				self::assertSame(ViewerContext::ROLE_EXTERNAL, $card->getCreatorRole());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->create(5, 'A card', 'bob');
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
			->method('recordChange')
			->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice');
	}

	public function testCreatePlacesOnTopWhenBoardOptsIn(): void {
		$board = $this->board();
		$board->setNewCardsOnTop(true);
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('findFirstInStack')->with(5)
			->willReturn($this->card(8, 5, 1, 'J'));
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				// before('J') === 'I' — the new card sorts above the current head.
				self::assertSame('I', $card->getSortKey());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice');
	}

	public function testCreateRejectsEmptyTitle(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
			->method('recordChange')
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
		// Every attempt collides on the unique index; after MAX_CREATE_ATTEMPTS (5)
		// tries the create surfaces a retryable 409 (OverflowException).
		$this->cardMapper->expects(self::exactly(5))
			->method('insert')
			->willReturnCallback(fn (Card $card): Card => throw $this->uniqueViolation());
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(\OverflowException::class);
		$this->service->create(5, 'A card', 'alice');
	}

	/**
	 * #3579: the card INSERT and its CREATE change row are one transaction. If the
	 * change-row write throws, the whole transaction rolls back - no orphan card
	 * is left without its delta-sync row, and no realtime push is emitted for a
	 * mutation that never landed.
	 */
	public function testCreateRollsBackWhenChangeRowInsertThrows(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				$card->setId(9);
				return $card;
			});
		// The change-row insert fails - the transaction must roll back, and a
		// non-unique DB error is not retried (it propagates).
		$dbError = $this->createMock(\OCP\DB\Exception::class);
		$dbError->method('getReason')->willReturn(\OCP\DB\Exception::REASON_CONNECTION_LOST);
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->willThrowException($dbError);

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		// No push for a create that never committed.
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');
		// The board-watcher fan-out is downstream of commit - never reached either.
		$this->subscriptionService->expects(self::never())->method('notifyBoardCardCreated');

		$this->expectException(\OCP\DB\Exception::class);
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice');
	}

	// ---- create: human-id (board_seq) -------------------------------------

	public function testCreateAssignsNextBoardSequence(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		// The board already has 41 numbered cards → the next is 42.
		$this->cardMapper->method('nextBoardSeq')->with(1)->willReturn(42);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				self::assertSame(42, $card->getBoardSeq());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$card = $this->service->create(5, 'A card', 'alice');
		self::assertSame(42, $card->getBoardSeq());
	}

	public function testCreateStartsSequenceAtOneOnEmptyBoard(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->method('nextBoardSeq')->with(1)->willReturn(1);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				self::assertSame(1, $card->getBoardSeq());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice');
	}

	public function testCreateRecomputesSequenceAfterUniqueCollision(): void {
		// A concurrent create grabs seq 7 first; our insert of 7 collides, we
		// recompute (now 8) and succeed - no duplicate number is ever persisted.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->method('nextBoardSeq')->with(1)
			->willReturnOnConsecutiveCalls(7, 8);
		$attempt = 0;
		$this->cardMapper->expects(self::exactly(2))
			->method('insert')
			->willReturnCallback(function (Card $card) use (&$attempt): Card {
				$attempt++;
				if ($attempt === 1) {
					self::assertSame(7, $card->getBoardSeq());
					throw $this->uniqueViolation();
				}
				self::assertSame(8, $card->getBoardSeq());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());

		$card = $this->service->create(5, 'A card', 'alice');
		self::assertSame(8, $card->getBoardSeq());
	}

	public function testCreatePersistsOptionalDuedateAndAllDayOnInsert(): void {
		// A natural-date token (#3416) is resolved client-side to an all-day ISO
		// datetime; create() persists it (normalized to UTC) on the same INSERT and
		// appends exactly one change row - no create-then-update round-trip.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				self::assertSame(
					'2026-08-05T00:00:00+00:00',
					$card->getDuedate()?->format(\DateTimeInterface::ATOM)
				);
				self::assertTrue($card->getAllDay());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_CREATE, 'alice')
			->willReturn(new Change());

		$card = $this->service->create(5, 'Ship it', 'alice', '2026-08-05T00:00:00.000Z', true);
		self::assertSame(9, $card->getId());
	}

	public function testCreateNormalizesDuedateToUtc(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->method('insert')->willReturnCallback(static function (Card $card): Card {
			$card->setId(9);
			return $card;
		});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$card = $this->service->create(5, 'A card', 'alice', '2026-08-01T10:00:00+02:00');
		self::assertSame(
			'2026-08-01T08:00:00+00:00',
			$card->getDuedate()?->format(\DateTimeInterface::ATOM)
		);
	}

	public function testCreateWithoutDuedateLeavesItUnset(): void {
		// Back-compat: the default create path carries no due date (null $duedate).
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				self::assertNull($card->getDuedate());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice');
	}

	public function testCreateRejectsInvalidDuedateBeforeInsert(): void {
		// A malformed due date fails the create cleanly (400) before any INSERT or
		// change row, matching update()'s validation via parseDuedate.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('insert');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(InvalidInputException::class);
		$this->service->create(5, 'A card', 'alice', 'tomorrow');
	}

	public function testCreateWithEmptyDuedateStringLeavesItUnset(): void {
		// '' is "no due date" (parseDuedate returns null), same as update().
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (Card $card): Card {
				self::assertNull($card->getDuedate());
				$card->setId(9);
				return $card;
			});
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$this->service->create(5, 'A card', 'alice', '');
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

	public function testFindThrowsDoesNotExistForHiddenCard(): void {
		// Visibility gate (#3743): a card the viewer may not see 404s exactly
		// like a missing id - never a 403 (no existence oracle).
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->visibilityGuard->method('assertVisible')
			->willThrowException(new DoesNotExistException('hidden'));

		$this->expectException(DoesNotExistException::class);
		$this->service->find(9, 'alice');
	}

	// ---- findByRef (board-scoped PREFIX-<seq> resolution, #3611) -----------

	private function boardWithPrefix(string $prefix, int $id = 1): Board {
		$board = $this->board($id);
		$board->setPrefix($prefix);
		return $board;
	}

	public function testFindByRefResolvesToCardOnMatchingBoardPrefix(): void {
		$board = $this->boardWithPrefix('KAN');
		$card = $this->card(42);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_READ);
		$this->cardMapper->expects(self::once())
			->method('findByBoardAndSeq')
			->with(1, 123, self::isInstanceOf(ViewerContext::class))
			->willReturn($card);

		self::assertSame($card, $this->service->findByRef(1, 'KAN-123', 'alice'));
	}

	public function testFindByRefIsCaseInsensitiveOnTheReference(): void {
		$board = $this->boardWithPrefix('KAN');
		$card = $this->card(42);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('findByBoardAndSeq')
			->with(1, 7, self::isInstanceOf(ViewerContext::class))->willReturn($card);

		self::assertSame($card, $this->service->findByRef(1, 'kan-7', 'alice'));
	}

	public function testFindByRefReturnsNullForUnknownSeq(): void {
		$board = $this->boardWithPrefix('KAN');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('findByBoardAndSeq')
			->with(1, 999, self::isInstanceOf(ViewerContext::class))->willReturn(null);

		self::assertNull($this->service->findByRef(1, 'KAN-999', 'alice'));
	}

	public function testFindByRefReturnsNullWhenPrefixDoesNotMatchBoard(): void {
		// The board's prefix is KAN; a reference to another prefix is not
		// resolvable here (board-scoped, per-board prefixes) - and never touches
		// the mapper.
		$board = $this->boardWithPrefix('KAN');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->expects(self::never())->method('findByBoardAndSeq');

		self::assertNull($this->service->findByRef(1, 'OTHER-1', 'alice'));
	}

	public function testFindByRefReturnsNullForMalformedReference(): void {
		$board = $this->boardWithPrefix('KAN');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->expects(self::never())->method('findByBoardAndSeq');

		self::assertNull($this->service->findByRef(1, 'not a ref', 'alice'));
	}

	public function testFindByRefFallsBackToDefaultPrefixForUnprefixedBoard(): void {
		// A board created before the prefix backfill has a null prefix; it defaults
		// to the shared "KAN", so a KAN-<n> reference still resolves.
		$board = $this->board(); // no prefix set
		$card = $this->card(42);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('findByBoardAndSeq')
			->with(1, 5, self::isInstanceOf(ViewerContext::class))->willReturn($card);

		self::assertSame($card, $this->service->findByRef(1, 'KAN-5', 'alice'));
	}

	public function testFindByRefAssertsReadPermissionBeforeResolving(): void {
		$board = $this->boardWithPrefix('KAN');
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('findByBoardAndSeq');

		$this->expectException(NotPermittedException::class);
		$this->service->findByRef(1, 'KAN-1', 'alice');
	}

	// ---- update -----------------------------------------------------------

	public function testUpdateAppliesFieldsAndWritesChangeRow(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
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

	// ---- description optimistic concurrency (#9845) -----------------------
	//
	// `baseLastModified` is OPTIONAL: omitting it keeps the historical
	// last-writer-wins behaviour (existing API clients, the MCP server). When it
	// is supplied, a description write based on a version the card has already
	// moved past is REFUSED rather than clobbering the other author's text.

	/** A card whose description was last written at $lastModified. */
	private function describedCard(string $description, int $lastModified): Card {
		$card = $this->card();
		$card->setDescription($description);
		$card->setLastModified($lastModified);
		return $card;
	}

	public function testUpdateRejectsDescriptionWriteBasedOnStaleVersion(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->describedCard('theirs', 200));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// The write must be refused BEFORE anything is persisted or logged.
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		try {
			$this->service->update(9, null, 'mine', null, null, null, 'bob', baseLastModified: 100);
			self::fail('Expected a DescriptionConflictException');
		} catch (DescriptionConflictException $e) {
			// The rejection carries the CURRENT text + version so the client can
			// show both sides and let the user recover their own draft.
			self::assertSame('description_conflict', $e->getMessage());
			self::assertSame('theirs', $e->getCurrentDescription());
			self::assertSame(200, $e->getCurrentLastModified());
		}
	}

	public function testUpdateAcceptsDescriptionWriteOnTheCurrentVersion(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->describedCard('theirs', 200));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, 'mine', null, null, null, 'bob', baseLastModified: 200);
		self::assertSame('mine', $updated->getDescription());
	}

	public function testUpdateWithoutABaseVersionStaysLastWriterWins(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->describedCard('theirs', 200));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// No base version supplied - exactly the pre-#9845 behaviour.
		$updated = $this->service->update(9, null, 'mine', null, null, null, 'bob');
		self::assertSame('mine', $updated->getDescription());
	}

	public function testUpdateDoesNotConflictWhenTheTextIsAlreadyIdentical(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->describedCard('same text', 200));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// A stale base, but the write would not change a byte - nothing to lose,
		// so it must not be surfaced as a conflict.
		$updated = $this->service->update(9, null, 'same text', null, null, null, 'bob', baseLastModified: 100);
		self::assertSame('same text', $updated->getDescription());
	}

	public function testUpdateBaseVersionDoesNotBlockNonDescriptionFields(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->describedCard('theirs', 200));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// The guard is description-scoped: a title save from the same open card
		// is never blocked by somebody else's unrelated edit.
		$updated = $this->service->update(9, 'Renamed', null, null, null, null, 'bob', baseLastModified: 100);
		self::assertSame('Renamed', $updated->getTitle());
		self::assertSame('theirs', $updated->getDescription());
	}

	public function testUpdateChecksPermissionBeforeTheConflictGuard(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->describedCard('theirs', 200));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('update');

		// A conflicting base must not turn a permission denial into a 409.
		$this->expectException(NotPermittedException::class);
		$this->service->update(9, null, 'mine', null, null, null, 'mallory', baseLastModified: 100);
	}

	// ---- description revision token (#9848) -------------------------------
	//
	// `baseDescriptionRevision` replaces the coarse timestamp with a per-card
	// counter enforced by a CONDITIONAL UPDATE inside the write transaction
	// (CardMapper::claimDescriptionRevision). These cases pin the branching and
	// the transaction boundary; the "exactly one of two concurrent writers wins"
	// property is untestable against a mocked mapper and is covered by
	// tests/e2e/description-conflict.spec.js against a real database.

	/** A card whose description sits at revision $revision. */
	private function revisionedCard(string $description, int $revision, int $lastModified = 200): Card {
		$card = $this->describedCard($description, $lastModified);
		$card->setDescriptionRevision($revision);
		return $card;
	}

	public function testUpdateClaimsTheDescriptionRevisionAndAdvancesIt(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->revisionedCard('theirs', 3));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())
			->method('claimDescriptionRevision')
			->with(9, 3)
			->willReturn(1);
		$this->cardMapper->expects(self::once())->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$updated = $this->service->update(9, null, 'mine', null, null, null, 'bob', baseDescriptionRevision: 3);
		self::assertSame('mine', $updated->getDescription());
		// The claimed value is mirrored onto the entity, so the response re-seeds
		// the editor with the version it just created (saving twice in a row from
		// the same editor must not 409 against the user's own write).
		self::assertSame(4, $updated->getDescriptionRevision());
	}

	public function testUpdateRejectsTheDescriptionWriteWhenTheClaimIsLost(): void {
		// find() twice: once to load the card, once to re-read the winner AFTER
		// the transaction was rolled back.
		$this->cardMapper->method('find')->with(9)->willReturnOnConsecutiveCalls(
			$this->revisionedCard('baseline', 3),
			$this->revisionedCard('theirs', 4, 250),
		);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// Somebody else's description write landed between the read and the claim.
		$this->cardMapper->method('claimDescriptionRevision')->with(9, 3)->willReturn(0);
		// Nothing may be persisted or logged, and the realtime push must not fire.
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');

		try {
			$this->service->update(9, null, 'mine', null, null, null, 'bob', baseDescriptionRevision: 3);
			self::fail('Expected a DescriptionConflictException');
		} catch (DescriptionConflictException $e) {
			self::assertSame('description_conflict', $e->getMessage());
			self::assertSame('theirs', $e->getCurrentDescription());
			self::assertSame(250, $e->getCurrentLastModified());
			// The revision a retry has to be based on.
			self::assertSame(4, $e->getCurrentRevision());
		}
	}

	public function testUpdateWithoutABaseRevisionSkipsTheClaimButStillAdvancesIt(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->revisionedCard('theirs', 3));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// Unguarded callers (the MCP server, third-party API clients) keep
		// last-writer-wins - the CAS must not run for them...
		$this->cardMapper->expects(self::never())->method('claimDescriptionRevision');
		// ...but the counter still moves, or a guarded editor seeded before this
		// write would silently overwrite it on its next save. It moves via the
		// mapper's in-SQL bump, never by incrementing the value this request read
		// before the transaction opened.
		$this->cardMapper->expects(self::once())
			->method('bumpDescriptionRevision')
			->with(9)
			->willReturn(4);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, 'mine', null, null, null, 'bob');
		self::assertSame('mine', $updated->getDescription());
		// The bumped value is what the response carries back.
		self::assertSame(4, $updated->getDescriptionRevision());
	}

	public function testUpdateDoesNotClaimWhenTheTextIsAlreadyIdentical(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->revisionedCard('same text', 3));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// A stale base, but the write would not change a byte: nothing to lose, so
		// no claim, no conflict, and the counter stays put.
		$this->cardMapper->expects(self::never())->method('claimDescriptionRevision');
		$this->cardMapper->expects(self::never())->method('bumpDescriptionRevision');
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, 'same text', null, null, null, 'bob', baseDescriptionRevision: 1);
		self::assertSame('same text', $updated->getDescription());
		self::assertSame(3, $updated->getDescriptionRevision());
	}

	public function testUpdateBaseRevisionDoesNotBlockNonDescriptionFields(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->revisionedCard('theirs', 3));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// The claim is description-scoped: a title save from the same open card is
		// never blocked by somebody else's unrelated edit.
		$this->cardMapper->expects(self::never())->method('claimDescriptionRevision');
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, 'Renamed', null, null, null, null, 'bob', baseDescriptionRevision: 1);
		self::assertSame('Renamed', $updated->getTitle());
		self::assertSame('theirs', $updated->getDescription());
	}

	public function testUpdateChecksPermissionBeforeTheRevisionClaim(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->revisionedCard('theirs', 3));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('claimDescriptionRevision');
		$this->cardMapper->expects(self::never())->method('update');

		// A stale base must not turn a permission denial into a 409.
		$this->expectException(NotPermittedException::class);
		$this->service->update(9, null, 'mine', null, null, null, 'mallory', baseDescriptionRevision: 1);
	}

	public function testUpdateRevisionSupersedesTheLegacyTimestampGuard(): void {
		// Both tokens sent: the revision wins, so the coarse timestamp guard - which
		// over-reports because `lastModified` also moves for unrelated edits - must
		// not fire on its own.
		$this->cardMapper->method('find')->with(9)->willReturn($this->revisionedCard('theirs', 3));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())->method('claimDescriptionRevision')->willReturn(1);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(
			9, null, 'mine', null, null, null, 'bob',
			baseLastModified: 100,
			baseDescriptionRevision: 3,
		);
		self::assertSame('mine', $updated->getDescription());
	}

	public function testUpdateWithBothTokensStillConflictsOnTheRevision(): void {
		// The mirror of the case above: both tokens sent and the CAS loses. The
		// 409 must come from the revision path (rolled-back transaction, re-read
		// winner) and not from the coarse timestamp guard, which would have thrown
		// before any setter ran and reported the pre-transaction values.
		$this->cardMapper->method('find')->with(9)->willReturnOnConsecutiveCalls(
			$this->revisionedCard('baseline', 3),
			$this->revisionedCard('theirs', 4, 250),
		);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())->method('claimDescriptionRevision')->willReturn(0);
		$this->cardMapper->expects(self::never())->method('update');
		$this->db->expects(self::once())->method('rollBack');

		try {
			$this->service->update(
				9, null, 'mine', null, null, null, 'bob',
				baseLastModified: 100,
				baseDescriptionRevision: 3,
			);
			self::fail('Expected a DescriptionConflictException');
		} catch (DescriptionConflictException $e) {
			self::assertSame('theirs', $e->getCurrentDescription());
			self::assertSame(4, $e->getCurrentRevision());
		}
	}

	// ---- granular activity verbs (#70) ------------------------------------
	//
	// The card-field update path stamps a field-specific verb when EXACTLY one
	// tracked field changed; zero or more than one keep the generic VERB_UPDATED.
	// Verb-only (no from/to values). We capture the 6th arg to recordChange()
	// (the verb) via a callback, matching the boundary the change row is written at.

	/**
	 * Wires the card + board + mapper mocks and returns a reference whose value
	 * becomes the verb passed to the single expected recordChange() call.
	 *
	 * @param-out int|null $captured
	 */
	private function captureUpdateVerb(Card $card, ?Board $board = null): \stdClass {
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($board ?? $this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$holder = new \stdClass();
		$holder->verb = null;
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->willReturnCallback(function (...$args) use ($holder): Change {
				$holder->verb = $args[5] ?? null;
				// A real change row always has an id; the description path reads it
				// to link the detail row, so the returned stub must carry one.
				$c = new Change();
				$c->setId(1);
				return $c;
			});
		return $holder;
	}

	public function testUpdateTitleOnlyStampsRenamedVerb(): void {
		$verb = $this->captureUpdateVerb($this->card());
		$this->service->update(9, 'Renamed', null, null, null, null, 'alice');
		self::assertSame(Change::VERB_RENAMED, $verb->verb);
	}

	public function testUpdateDescriptionOnlyStampsDescriptionVerb(): void {
		$verb = $this->captureUpdateVerb($this->card());
		$this->service->update(9, null, 'Fresh description', null, null, null, 'alice');
		self::assertSame(Change::VERB_DESCRIPTION_UPDATED, $verb->verb);
	}

	public function testUpdateDescriptionOnlyWritesChangeDetailWithFromTo(): void {
		$card = $this->card();
		$card->setDescription('Old body');
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		// recordChange must return a Change with an id so the detail links to it.
		$this->changeNotifier->method('recordChange')->willReturnCallback(static function (): Change {
			$c = new Change();
			$c->setId(777);
			return $c;
		});

		$captured = new \stdClass();
		$captured->changeId = null;
		$captured->from = 'unset';
		$captured->to = 'unset';
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->willReturnCallback(function (int $changeId, ?string $from, ?string $to) use ($captured): ChangeDetail {
				$captured->changeId = $changeId;
				$captured->from = $from;
				$captured->to = $to;
				return new ChangeDetail();
			});

		$this->service->update(9, null, 'New body', null, null, null, 'alice');

		self::assertSame(777, $captured->changeId);
		self::assertSame('Old body', $captured->from);
		self::assertSame('New body', $captured->to);
	}

	public function testUpdateRenameWritesTitleFromToDetail(): void {
		// A rename now records the old/new title so the feed shows both.
		$card = $this->card(); // title 'Existing card'
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$change = new Change();
		$change->setId(501);
		$this->changeNotifier->method('recordChange')->willReturn($change);
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(501, 'Existing card', 'Renamed')
			->willReturn(new ChangeDetail());

		$this->service->update(9, 'Renamed', null, null, null, null, 'alice');
	}

	public function testUpdatePriorityWritesPriorityLabelFromToDetail(): void {
		// Priority verb records the from/to as human labels (Medium → Urgent).
		$card = $this->card();
		$card->setPriority(2); // Medium
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$change = new Change();
		$change->setId(502);
		$this->changeNotifier->method('recordChange')->willReturn($change);
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(502, 'Medium', 'Urgent')
			->willReturn(new ChangeDetail());

		$this->service->update(9, null, null, null, null, null, 'alice', Card::PRIORITY_URGENT);
	}

	public function testUpdateTimestampOnlyStatusWritesStatusLabelFromToDetail(): void {
		// Timestamp-only status change records the from/to status labels.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);
		$card = $this->card(); // no doneAt/startedAt → 'not_started'
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$change = new Change();
		$change->setId(503);
		$this->changeNotifier->method('recordChange')->willReturn($change);
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(503, 'Not started', 'In progress')
			->willReturn(new ChangeDetail());

		$this->service->update(9, null, null, null, null, null, 'alice', null, null, 'in_progress');
	}

	public function testUpdateMultipleFieldsWritesNoChangeDetail(): void {
		// A multi-field save keeps the generic verb and writes NO detail row.
		$this->captureUpdateVerb($this->card());
		$this->changeDetailMapper->expects(self::never())->method('insertDetail');
		$this->service->update(9, 'Renamed', null, '2026-08-15T10:00:00+00:00', null, null, 'alice');
	}

	public function testUpdateDueDateOnlyStampsDueVerb(): void {
		$verb = $this->captureUpdateVerb($this->card());
		$this->service->update(9, null, null, '2026-08-15T10:00:00+00:00', null, null, 'alice');
		self::assertSame(Change::VERB_DUE_CHANGED, $verb->verb);
	}

	public function testUpdateStartDateOnlyStampsStartVerb(): void {
		$verb = $this->captureUpdateVerb($this->card());
		$this->service->update(9, null, null, null, null, null, 'alice', null, '2026-08-01T00:00:00+00:00');
		self::assertSame(Change::VERB_START_CHANGED, $verb->verb);
	}

	public function testUpdateRejectsEndDateBeforeStartDate(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// The inverted window must be refused before anything is persisted.
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(\OCA\Kanso\Service\InvalidInputException::class);
		// End (Aug 1) is before Start (Aug 10) → rejected.
		$this->service->update(9, null, null, '2026-08-01T00:00:00+00:00', null, null, 'alice', null, '2026-08-10T00:00:00+00:00');
	}

	public function testUpdateAllowsEndDateOnOrAfterStartDate(): void {
		$this->captureUpdateVerb($this->card());
		// Start Aug 1, End Aug 10 → a valid window, so the update goes through.
		$updated = $this->service->update(9, null, null, '2026-08-10T00:00:00+00:00', null, null, 'alice', null, '2026-08-01T00:00:00+00:00');
		self::assertSame('2026-08-01', $updated->getStartDate()->format('Y-m-d'));
		self::assertSame('2026-08-10', $updated->getDuedate()->format('Y-m-d'));
	}

	public function testUpdatePriorityOnlyStampsPriorityVerb(): void {
		$verb = $this->captureUpdateVerb($this->card());
		$this->service->update(9, null, null, null, null, null, 'alice', Card::PRIORITY_URGENT);
		self::assertSame(Change::VERB_PRIORITY_CHANGED, $verb->verb);
	}

	public function testUpdateEstimateOnlyStampsEstimateVerb(): void {
		$board = $this->board();
		$board->setEstimateScale('fibonacci');
		$verb = $this->captureUpdateVerb($this->card(), $board);
		$this->service->update(9, null, null, null, null, null, 'alice', null, null, null, '8');
		self::assertSame(Change::VERB_ESTIMATE_CHANGED, $verb->verb);
	}

	public function testUpdateTypeOnlyStampsTypeVerb(): void {
		$verb = $this->captureUpdateVerb($this->card());
		// positional: …, uid, priority, startDate, status, estimate, allDay,
		// dueReminderDayBefore, coverColor, type
		$this->service->update(9, null, null, null, null, null, 'alice', null, null, null, null, null, null, null, Card::TYPES[0]);
		self::assertSame(Change::VERB_TYPE_CHANGED, $verb->verb);
	}

	public function testUpdateTimestampOnlyStatusStampsStatusVerb(): void {
		// Current column has no workflow role and the board maps no target column,
		// so the status applies timestamp-only (no move) → VERB_STATUS_CHANGED.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);
		$verb = $this->captureUpdateVerb($this->card());
		$this->service->update(9, null, null, null, null, null, 'alice', null, null, 'in_progress');
		self::assertSame(Change::VERB_STATUS_CHANGED, $verb->verb);
	}

	public function testUpdateMultipleFieldsFallsBackToGenericVerb(): void {
		$verb = $this->captureUpdateVerb($this->card());
		// Title AND due date change together → generic VERB_UPDATED.
		$this->service->update(9, 'Renamed', null, '2026-08-15T10:00:00+00:00', null, null, 'alice');
		self::assertSame(Change::VERB_UPDATED, $verb->verb);
	}

	public function testUpdateNoOpTitleDoesNotMislabelAsRenamed(): void {
		// Re-saving the SAME title is not a change → no tracked field moved →
		// generic VERB_UPDATED, never VERB_RENAMED.
		$verb = $this->captureUpdateVerb($this->card());
		$this->service->update(9, 'Existing card', null, null, null, null, 'alice');
		self::assertSame(Change::VERB_UPDATED, $verb->verb);
	}

	public function testUpdateSucceedsForExternalMemberOnAVisibleCard(): void {
		// The external-role happy path (#3744): an external member with EDIT
		// mutates the cards the visibility scope shows them - the role cap
		// only bites on SHARE/MANAGE surfaces and board structure, never here.
		$this->resolvedRole = ViewerContext::ROLE_EXTERNAL;
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, 'Client rename', null, null, null, null, 'client');
		self::assertSame('Client rename', $updated->getTitle());
	}

	public function testUpdateLeavesFieldsUnchangedOnNull(): void {
		$card = $this->card();
		$card->setDescription('Keep me');
		$card->setDuedate(new \DateTime('2026-08-01T10:00:00+00:00'));
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, '', null, null, 'alice');
		self::assertNull($updated->getDuedate());
	}

	public function testUpdateSetsAllDayFlag(): void {
		$card = $this->card();
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// Positional: …, uid, priority, startDate, status, estimate, allDay.
		$updated = $this->service->update(9, null, null, '2026-08-15T00:00:00+00:00', null, null, 'alice', null, null, null, null, true);
		self::assertTrue($updated->getAllDay());
		self::assertInstanceOf(\DateTime::class, $updated->getDuedate());
	}

	public function testUpdateClearingDuedateAlsoClearsAllDay(): void {
		$card = $this->card();
		$card->setDuedate(new \DateTime('2026-08-01T00:00:00+00:00'));
		$card->setAllDay(true);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, '', null, null, 'alice');
		self::assertNull($updated->getDuedate());
		self::assertFalse($updated->getAllDay());
	}

	public function testUpdateMovingDuedateResetsReminderMarkers(): void {
		$card = $this->card();
		$card->setDuedate(new \DateTime('2026-08-01T10:00:00+00:00'));
		$card->setDueReminderSent(1_700_000_000);
		$card->setDayBeforeReminderSent(1_700_000_000);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// Moving the due date forward re-arms both reminders (markers cleared).
		$updated = $this->service->update(9, null, null, '2026-08-05T10:00:00+00:00', null, null, 'alice');
		self::assertSame(0, $updated->getDueReminderSent());
		self::assertSame(0, $updated->getDayBeforeReminderSent());
	}

	public function testUpdateKeepingSameDuedateLeavesReminderMarkers(): void {
		$card = $this->card();
		$card->setDuedate(new \DateTime('2026-08-01T10:00:00+00:00'));
		$card->setDueReminderSent(1_700_000_000);
		$card->setDayBeforeReminderSent(1_700_000_000);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// Re-setting the identical due date must NOT re-arm (no re-spam).
		$updated = $this->service->update(9, null, null, '2026-08-01T10:00:00+00:00', null, null, 'alice');
		self::assertSame(1_700_000_000, $updated->getDueReminderSent());
		self::assertSame(1_700_000_000, $updated->getDayBeforeReminderSent());
	}

	public function testUpdateTogglesDayBeforeReminder(): void {
		$card = $this->card();
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// Positional: …, uid, priority, startDate, status, estimate, allDay, dueReminderDayBefore.
		$updated = $this->service->update(9, null, null, null, null, null, 'alice', null, null, null, null, null, true);
		self::assertTrue($updated->getDueReminderDayBefore());
	}

	public function testUpdateSetsAndClearsStartDate(): void {
		$card = $this->card();
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, null, null, 'alice', null, null, null, '');
		self::assertNull($updated->getEstimate());
	}

	// ---- update: cover colour (#3549) -------------------------------------

	public function testUpdateSetsCoverColor(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		// Positional: …, uid, priority, startDate, status, estimate, allDay,
		// dueReminderDayBefore, coverColor.
		$updated = $this->service->update(9, null, null, null, null, null, 'alice', null, null, null, null, null, null, '3498db');
		self::assertSame('3498db', $updated->getCoverColor());
	}

	public function testUpdateClearsCoverColorOnEmptyString(): void {
		$card = $this->card();
		$card->setCoverColor('e74c3c');
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, null, null, 'alice', null, null, null, null, null, null, '');
		self::assertNull($updated->getCoverColor());
	}

	public function testUpdateRejectsInvalidCoverColor(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(InvalidInputException::class);
		// Not a bare 6-hex value (a leading '#' is rejected by ColorValidator).
		$this->service->update(9, null, null, null, null, null, 'alice', null, null, null, null, null, null, '#fff');
	}

	public function testUpdateLeavesCoverColorUnchangedOnNull(): void {
		$card = $this->card();
		$card->setCoverColor('2ecc71');
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// coverColor omitted (null) → the existing cover colour is preserved.
		$updated = $this->service->update(9, 'Renamed', null, null, null, null, 'alice');
		self::assertSame('2ecc71', $updated->getCoverColor());
	}

	public function testCoverColorIsSerializedInSummaryAndDetail(): void {
		$card = $this->card();
		$card->setCoverColor('9b59b6');

		$summary = $card->jsonSerializeSummary();
		self::assertArrayHasKey('coverColor', $summary);
		self::assertSame('9b59b6', $summary['coverColor']);

		// The detail payload is the summary + description, so it carries it too.
		$detail = $card->jsonSerialize();
		self::assertSame('9b59b6', $detail['coverColor']);
	}

	public function testUpdateParsesAtomDuedateAndNormalizesToUtc(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, 'tomorrow', null, null, 'alice');
	}

	public function testUpdateRejectsRolledOverDuedate(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		// createFromFormat would silently roll February 30th to March 2nd.
		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, '2026-02-30T12:00:00Z', null, null, 'alice');
	}

	public function testUpdateDoneStampsDoneAt(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, true, null, 'alice');
		self::assertGreaterThan(0, $updated->getDoneAt());
	}

	public function testUpdateDoneIsIdempotent(): void {
		$card = $this->card();
		$card->setDoneAt(12345);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, true, null, 'alice');
		self::assertSame(12345, $updated->getDoneAt());
	}

	public function testUpdateUndoneClearsDoneAt(): void {
		$card = $this->card();
		$card->setDoneAt(12345);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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

	// ---- update visibility (#3743) ----------------------------------------

	public function testUpdateVisibilityByCardOwnerSetsIt(): void {
		// The card's owner ('alice') may narrow their own card.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, null, null, 'alice', visibility: 'internal');
		self::assertSame('internal', $updated->getVisibility());
	}

	public function testUpdateVisibilityDeniedForNonOwnerNonManager(): void {
		// 'bob' is neither the card's owner nor a manager (the setUp contextFor
		// stub resolves isManager=false) - flipping someone else's card across
		// the fence is forbidden.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(NotPermittedException::class);
		$this->service->update(9, null, null, null, null, null, 'bob', visibility: 'private');
	}

	public function testUpdateRejectsUnknownVisibility(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, null, null, null, 'alice', visibility: 'bogus');
	}

	public function testUpdateOnHiddenCardThrowsDoesNotExist(): void {
		// A hidden card is unmutable: the write 404s like a missing id.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->visibilityGuard->method('assertVisible')
			->willThrowException(new DoesNotExistException('hidden'));
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(DoesNotExistException::class);
		$this->service->update(9, 'Renamed', null, null, null, null, 'alice');
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
			->method('recordChange')
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

	public function testDeleteOnHiddenCardThrowsDoesNotExist(): void {
		// Same unmutability rule for delete: a hidden card cannot be removed.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->visibilityGuard->method('assertVisible')
			->willThrowException(new DoesNotExistException('hidden'));
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(DoesNotExistException::class);
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
			->method('recordChange')
			->with(
				1,
				Change::ENTITY_CARD,
				9,
				Change::ACTION_MOVE,
				'alice',
				Change::VERB_MOVED,
			)
			->willReturn(new Change());
		// The realtime broadcast fires only after the transaction commits.
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame('I', $moved->getSortKey());
		self::assertSame(6, $moved->getStackId());
	}

	public function testMoveAcrossStacksWritesSourceAndTargetColumnDetail(): void {
		// A move between two different columns records the from/to column titles.
		$source = $this->stack(5);
		$source->setTitle('To Do');
		$target = $this->stack(6);
		$target->setTitle('In Progress');
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(9, 5, 1, 'K'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $source,
			6 => $target,
		});
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$change = new Change();
		$change->setId(601);
		$this->changeNotifier->method('recordChange')->willReturn($change);
		$this->changeDetailMapper->expects(self::once())
			->method('insertDetail')
			->with(601, 'To Do', 'In Progress')
			->willReturn(new ChangeDetail());

		$this->service->move(9, 6, null, 'alice');
	}

	public function testSameStackReorderWritesNoColumnDetail(): void {
		// A pure reorder within the same column is not a move-between-columns, so
		// no from/to detail is stored (source and target stacks are identical).
		$this->cardMapper->method('find')->willReturnCallback(fn (int $id): Card => match ($id) {
			9 => $this->card(9, 5, 1, 'V'),
			10 => $this->card(10, 5, 1, 'I'),
		});
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5));
		$this->cardMapper->method('findNextInStack')->with(5, 'I')->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$change = new Change();
		$change->setId(602);
		$this->changeNotifier->method('recordChange')->willReturn($change);
		$this->changeDetailMapper->expects(self::never())->method('insertDetail');

		$this->service->move(9, 5, 10, 'alice');
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
			->method('recordChange')
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());
		// The push fires exactly once — after the SUCCESSFUL commit, never for the
		// rolled-back first attempt.
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);
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
		$this->changeNotifier->expects(self::never())->method('recordChange');
		// Nothing committed → no realtime broadcast for a move that never landed.
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');
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
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(NotPermittedException::class);
		$this->service->move(9, 6, null, 'alice');
	}

	public function testMoveToDoneBlockedWhileGatedReviewSitsUpstream(): void {
		// A gated (deferred) review is still pending, i.e. unapproved, so the
		// done-gate blocks exactly as it does for any unapproved review (#3588):
		// the gate consumes hasUnapprovedReviews(), which counts pending rows
		// regardless of whether they are gated.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(9, 5, 1, 'V'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5, 1, Stack::ROLE_REVIEW),
			6 => $this->stack(6, 1, Stack::ROLE_DONE),
		});
		$this->cardReviewMapper->method('hasUnapprovedReviews')->with(9)->willReturn(true);
		$this->db->expects(self::never())->method('beginTransaction');

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame(6, $moved->getStackId());
		// Into a done-role stack, done-automation still stamps done_at.
		self::assertGreaterThan(0, $moved->getDoneAt());
	}

	public function testMoveIntoDoneFromNonReviewStackIgnoresReviews(): void {
		// The gate only fires when leaving a review-role stack - unapproved
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_MOVE, 'alice', Change::VERB_MOVED)
			->willReturn(new Change());
		// Push is emitted once, AFTER commit (not inside the transaction).
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertSame(12345, $moved->getDoneAt());
	}

	public function testMoveIntoBacklogStackClearsStatus(): void {
		// A column's role IS its status: a done card dragged into a backlog-role
		// column (5, done) → (6, backlog) is reset to "not started" - both
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertGreaterThan(0, $moved->getStartedAt());
		self::assertSame(0, $moved->getDoneAt());
	}

	public function testMoveIntoInProgressStackReopensADoneCard(): void {
		// The column's role is its status: dragging a done card into an
		// in-progress column reopens it - done cleared, started stamped.
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$moved = $this->service->move(9, 6, null, 'alice');
		self::assertGreaterThan(0, $moved->getStartedAt());
		self::assertSame(0, $moved->getDoneAt());
	}

	public function testUpdateStatusTransitions(): void {
		$card = $this->card();
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// The card's column carries no workflow role and the board maps none
		// (findByBoardAndRole → null by default), so status stays timestamp-only.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5));
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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

	public function testUpdateStatusToDoneMovesCardIntoDoneColumn(): void {
		// #54: setting the status to Done from the card view moves the card into the
		// board's Done-role column (6), not just stamps done_at in place. The change
		// is realised as a single MOVE row, never a status-only UPDATE.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(9, 5, 1, 'V'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5),
			6 => $this->stack(6, 1, Stack::ROLE_DONE),
		});
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_DONE)
			->willReturn($this->stack(6, 1, Stack::ROLE_DONE));
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_MOVE, 'alice', Change::VERB_MOVED)
			->willReturn(new Change());

		$moved = $this->service->update(9, null, null, null, null, null, 'alice', null, null, 'done');
		self::assertSame(6, $moved->getStackId());
		self::assertGreaterThan(0, $moved->getDoneAt());
	}

	public function testUpdateStatusNotStartedMovesCardBackToTodoColumn(): void {
		// #54: the sync runs backward too - marking a Done-column card "Not started"
		// carries it into the To do-role column and clears both timestamps.
		$card = $this->card(9, 5, 1, 'V');
		$card->setDoneAt(12345);
		$card->setStartedAt(500);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => match ($id) {
			5 => $this->stack(5, 1, Stack::ROLE_DONE),
			6 => $this->stack(6, 1, Stack::ROLE_TODO),
		});
		$this->stackMapper->method('findByBoardAndRole')->with(1, Stack::ROLE_TODO)
			->willReturn($this->stack(6, 1, Stack::ROLE_TODO));
		$this->cardMapper->method('findFirstInStack')->with(6)->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$moved = $this->service->update(9, null, null, null, null, null, 'alice', null, null, 'not_started');
		self::assertSame(6, $moved->getStackId());
		self::assertSame(0, $moved->getDoneAt());
		self::assertSame(0, $moved->getStartedAt());
	}

	public function testUpdateStatusLeavesCardWhenColumnAlreadyMatchesRole(): void {
		// A card in a Review-role column re-marked "In progress" stays put - Review
		// already represents that status, so it is not yanked into the In progress
		// column. Status still applies (started stamped), via a plain UPDATE.
		$card = $this->card(9, 5, 1, 'V');
		$card->setDoneAt(999);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5, 1, Stack::ROLE_REVIEW));
		$this->cardMapper->method('update')->willReturnArgument(0);
		// Status is the only field that moved and it applies timestamp-only (no
		// column move), so the change row is stamped VERB_STATUS_CHANGED (#70).
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice', Change::VERB_STATUS_CHANGED)
			->willReturn(new Change());

		$updated = $this->service->update(9, null, null, null, null, null, 'alice', null, null, 'in_progress');
		self::assertSame(5, $updated->getStackId());
		self::assertGreaterThan(0, $updated->getStartedAt());
		self::assertSame(0, $updated->getDoneAt());
	}

	public function testUpdateStatusStaysTimestampOnlyWhenNoWorkflowColumnMapped(): void {
		// A board that maps no workflow roles (findByBoardAndRole → null) keeps the
		// old behaviour: status stamps timestamps in place, the card never moves.
		$this->cardMapper->method('find')->with(9)->willReturn($this->card(9, 5, 1, 'V'));
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5));
		$this->stackMapper->method('findByBoardAndRole')->willReturn(null);
		$this->cardMapper->method('update')->willReturnArgument(0);
		// Timestamp-only status change (no workflow column) → VERB_STATUS_CHANGED (#70).
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice', Change::VERB_STATUS_CHANGED)
			->willReturn(new Change());

		$moved = $this->service->update(9, null, null, null, null, null, 'alice', null, null, 'done');
		self::assertSame(5, $moved->getStackId());
		self::assertGreaterThan(0, $moved->getDoneAt());
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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
			->method('recordChange')
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
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());

		$this->service->setParent(9, null, 'alice');
	}

	public function testSetParentClearingAlreadyUnparentedIsNoOp(): void {
		$child = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($child);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
		$this->changeNotifier->expects(self::never())->method('recordChange');

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
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

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
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());

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

	// ---- update type (#3402) ----------------------------------------------

	public function testUpdateSetsType(): void {
		$card = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());

		$result = $this->service->update(9, null, null, null, null, null, 'alice', type: 'bug');
		self::assertSame('bug', $result->getType());
	}

	public function testUpdateClearsTypeWithEmptyString(): void {
		$card = $this->card(9, 5, 1);
		$card->setType('feature');
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())->method('recordChange')->willReturn(new Change());

		$result = $this->service->update(9, null, null, null, null, null, 'alice', type: '');
		self::assertSame('', $result->getType());
	}

	public function testUpdateRejectsUnknownType(): void {
		$card = $this->card(9, 5, 1);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(9, null, null, null, null, null, 'alice', type: 'epic');
	}

	public function testUpdateTypeDeniedWithoutEditPermission(): void {
		$card = $this->card(9, 5, 1);
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'mallory', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->update(9, null, null, null, null, null, 'mallory', type: 'bug');
	}

	// ---- copy -------------------------------------------------------------

	private function label(int $id, int $boardId, string $title, ?string $color): \OCA\Kanso\Db\Label {
		$label = new \OCA\Kanso\Db\Label();
		$label->setId($id);
		$label->setBoardId($boardId);
		$label->setTitle($title);
		$label->setColor($color);
		return $label;
	}

	private function checklistItem(int $id, int $cardId, string $title, bool $done, ?\DateTime $dueDate = null): \OCA\Kanso\Db\ChecklistItem {
		$item = new \OCA\Kanso\Db\ChecklistItem();
		$item->setId($id);
		$item->setCardId($cardId);
		$item->setTitle($title);
		$item->setDone($done);
		if ($dueDate !== null) {
			$item->setDueDate($dueDate);
		}
		$item->setSortKey('I');
		return $item;
	}

	/**
	 * Same-board copy: content (title suffix / description / priority / estimate /
	 * status) is cloned, labels are re-assigned by id directly, checklist items
	 * are recreated with their done state, and comments/history/assignees are
	 * never touched.
	 */
	public function testCopySameBoardClonesContentLabelsAndChecklist(): void {
		$board = $this->board(1);
		$board->setEstimateScale('fibonacci');
		$targetStack = $this->stack(7, 1);

		$source = $this->card(9, 5, 1);
		$source->setTitle('Design the API');
		$source->setDescription('Full spec here');
		$source->setPriority(Card::PRIORITY_URGENT);
		$source->setStartedAt(1000);
		$source->setDoneAt(0);
		$source->setEstimate('3');

		$this->cardMapper->method('find')->willReturnMap([[9, $source]]);
		$this->stackMapper->method('find')->with(7)->willReturn($targetStack);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('findLastInStack')->with(7)->willReturn(null);

		// The create() shell insert + the second update() carrying the content.
		$inserted = null;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$inserted): Card {
			$c->setId(42);
			$inserted = $c;
			return $c;
		});
		$this->cardMapper->method('update')->willReturnCallback(static fn (Card $c): Card => $c);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// Same-board: source label ids re-assigned directly (no cross-board mapping).
		$this->cardLabelMapper->method('findLabelIdsByCard')->with(9)->willReturn([11, 12]);
		$this->labelMapper->expects(self::never())->method('findByBoard');
		$assigned = [];
		$this->labelService->method('assign')->willReturnCallback(function (int $cardId, int $labelId) use (&$assigned): void {
			$assigned[] = [$cardId, $labelId];
		});

		// Checklist: two items, second one done → each recreated in a single
		// addItem call carrying its done state (no separate toggle). The first
		// is a rich step (#3745): assigned + due + done-stamped on the source.
		// Clone policy: the DUE DATE rides the addItem call; assignee and
		// done_at have no addItem parameter at all, so they drop by
		// construction.
		$stepDue = new \DateTime('2026-08-14T18:00:00Z');
		$richStep = $this->checklistItem(1, 9, 'Step one', false, $stepDue);
		$richStep->setAssignedUser('client');
		$richStep->setAssignedRole(\OCA\Kanso\Access\ViewerContext::ROLE_EXTERNAL);
		$richStep->setAssignedAt(1000);
		$this->checklistItemMapper->method('findByCard')->with(9)->willReturn([
			$richStep,
			$this->checklistItem(2, 9, 'Step two', true),
		]);
		$added = [];
		$this->checklistService->method('addItem')->willReturnCallback(function (int $cardId, string $title, string $uid, bool $done = false, ?\DateTime $dueDate = null) use (&$added): \OCA\Kanso\Db\ChecklistItem {
			$added[] = [$title, $done, $dueDate?->getTimestamp()];
			$new = new \OCA\Kanso\Db\ChecklistItem();
			$new->setId(count($added) + 100);
			$new->setCardId($cardId);
			$new->setTitle($title);
			$new->setDone($done);
			return $new;
		});
		$this->checklistService->expects(self::never())->method('updateItem');
		// The clone path never re-assigns or re-stamps a step on the copy.
		$this->checklistService->expects(self::never())->method('assignItem');

		$copy = $this->service->copy(9, 7, 'alice');

		self::assertSame(42, $copy->getId());
		self::assertSame('Design the API (copy)', $copy->getTitle());
		self::assertSame('Full spec here', $copy->getDescription());
		self::assertSame(Card::PRIORITY_URGENT, $copy->getPriority());
		self::assertSame(1000, $copy->getStartedAt());
		self::assertSame(0, $copy->getDoneAt());
		self::assertSame('3', $copy->getEstimate());
		self::assertSame([[42, 11], [42, 12]], $assigned);
		self::assertSame(
			[['Step one', false, $stepDue->getTimestamp()], ['Step two', true, null]],
			$added,
			'the copy keeps each step due date; assignee/done_at drop by construction',
		);
	}

	/**
	 * Cross-board copy: labels map by title+color to the target board's labels;
	 * a source label with no title+color twin on the target is dropped. An
	 * estimate token the target scale rejects is likewise dropped.
	 */
	public function testCopyCrossBoardMapsLabelsByNameAndColorOrDrops(): void {
		$sourceBoard = $this->board(1);
		$targetBoard = $this->board(2);
		$targetBoard->setEstimateScale('none'); // rejects any estimate token
		$targetStack = $this->stack(7, 2);

		$source = $this->card(9, 5, 1);
		$source->setTitle('Cross move');
		$source->setEstimate('3'); // valid on a fibonacci board, dropped on 'none'

		$this->cardMapper->method('find')->willReturnMap([[9, $source]]);
		$this->stackMapper->method('find')->with(7)->willReturn($targetStack);
		$this->boardMapper->method('find')->willReturnMap([[1, $sourceBoard], [2, $targetBoard]]);
		$this->cardMapper->method('findLastInStack')->with(7)->willReturn(null);
		$this->cardMapper->method('insert')->willReturnCallback(static function (Card $c): Card {
			$c->setId(42);
			return $c;
		});
		$this->cardMapper->method('update')->willReturnCallback(static fn (Card $c): Card => $c);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());
		$this->checklistItemMapper->method('findByCard')->with(9)->willReturn([]);

		// Source card carries labels 11 (Bug/e01) and 12 (Secret/abc).
		$this->cardLabelMapper->method('findLabelIdsByCard')->with(9)->willReturn([11, 12]);
		$this->labelMapper->method('find')->willReturnMap([
			[11, $this->label(11, 1, 'Bug', 'e01e01')],
			[12, $this->label(12, 1, 'Secret', 'abcabc')],
		]);
		// Target board has a matching "Bug/e01e01" (id 71) but no "Secret/abcabc".
		$this->labelMapper->method('findByBoard')->with(2)->willReturn([
			$this->label(71, 2, 'Bug', 'e01e01'),
			$this->label(72, 2, 'Other', '000000'),
		]);
		$assigned = [];
		$this->labelService->method('assign')->willReturnCallback(function (int $cardId, int $labelId) use (&$assigned): void {
			$assigned[] = $labelId;
		});

		$copy = $this->service->copy(9, 7, 'alice');

		self::assertSame(2, $copy->getBoardId());
		self::assertNull($copy->getEstimate(), 'off-scale estimate is dropped on the target board');
		self::assertSame([71], $assigned, 'only the title+color twin is mapped; the unmatched label is dropped');
	}

	/**
	 * Copy needs EDIT on the TARGET board: a viewer with EDIT on the source but
	 * not on the target is rejected and no card is created.
	 */
	public function testCopyAssertsEditOnTargetBoard(): void {
		$sourceBoard = $this->board(1);
		$targetBoard = $this->board(2);
		$targetStack = $this->stack(7, 2);
		$source = $this->card(9, 5, 1);

		$this->cardMapper->method('find')->willReturnMap([[9, $source]]);
		$this->stackMapper->method('find')->with(7)->willReturn($targetStack);
		$this->boardMapper->method('find')->willReturnMap([[1, $sourceBoard], [2, $targetBoard]]);

		$this->permissionService->method('assertPermission')
			->willReturnCallback(static function (Board $board, string $uid, int $perm): void {
				// EDIT on the source (board 1) passes; EDIT on the target (board 2) denied.
				if ($board->getId() === 2) {
					throw new NotPermittedException();
				}
			});

		$this->cardMapper->expects(self::never())->method('insert');
		$this->labelService->expects(self::never())->method('assign');
		$this->checklistService->expects(self::never())->method('addItem');

		$this->expectException(NotPermittedException::class);
		$this->service->copy(9, 7, 'bob');
	}

	// ---- moveToBoard (#3679) ----------------------------------------------

	/**
	 * Cross-board MOVE happy path: the card is re-created on the target board
	 * (content + checklist carried), assignees/watchers that can READ the target
	 * cross over, the source is soft-deleted, and a change row lands on BOTH
	 * boards (CREATE on target, DELETE on source).
	 */
	public function testMoveToBoardRecreatesOnTargetAndSoftDeletesSource(): void {
		$sourceBoard = $this->board(1);
		$targetBoard = $this->board(2);
		$targetBoard->setEstimateScale('fibonacci');
		$targetStack = $this->stack(7, 2);

		$source = $this->card(9, 5, 1);
		$source->setTitle('Ship it');
		$source->setDescription('the spec');
		$source->setPriority(Card::PRIORITY_URGENT);
		$source->setEstimate('3');

		$this->cardMapper->method('find')->willReturnMap([[9, $source]]);
		$this->stackMapper->method('find')->with(7)->willReturn($targetStack);
		$this->boardMapper->method('find')->willReturnMap([[1, $sourceBoard], [2, $targetBoard]]);
		$this->cardMapper->method('findLastInStack')->with(7)->willReturn(null);
		$this->cardMapper->method('nextBoardSeq')->with(2)->willReturn(88);
		$this->cardMapper->method('findChildren')->with(9)->willReturn([]);

		// Everyone can read the target board here (READ bit set for any uid).
		$this->permissionService->method('getPermissions')->willReturn(PermissionService::PERMISSION_ALL);

		// Source carries no labels, one checklist item, one assignee, one watcher.
		// The item is a rich step (#3745): assigned + due + done-stamped - the
		// move keeps the DUE DATE but drops assignee/role/stamps (the frozen
		// role was resolved against the SOURCE board's ACL).
		$this->cardLabelMapper->method('findLabelIdsByCard')->with(9)->willReturn([]);
		$stepDue = new \DateTime('2026-08-14T18:00:00Z');
		$movedStep = $this->checklistItem(1, 9, 'Step one', true, $stepDue);
		$movedStep->setAssignedUser('bob');
		$movedStep->setAssignedRole(\OCA\Kanso\Access\ViewerContext::ROLE_INTERNAL);
		$movedStep->setAssignedAt(1000);
		$movedStep->setDoneAt(2000);
		$this->checklistItemMapper->method('findByCard')->with(9)->willReturn([
			$movedStep,
		]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->with(9)->willReturn(['bob']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(9)->willReturn(['carol']);

		$inserted = null;
		$this->cardMapper->method('insert')->willReturnCallback(function (Card $c) use (&$inserted): Card {
			$c->setId(42);
			$inserted = $c;
			return $c;
		});
		$softDeleted = null;
		$this->cardMapper->method('update')->willReturnCallback(function (Card $c) use (&$softDeleted): Card {
			if ($c->getId() === 9) {
				$softDeleted = $c;
			}
			return $c;
		});

		$assignedTo = [];
		$this->cardAssigneeMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, string $uid) use (&$assignedTo): \OCA\Kanso\Db\CardAssignee {
			$assignedTo[] = [$cardId, $uid];
			return new \OCA\Kanso\Db\CardAssignee();
		});
		$watchedBy = [];
		$this->subscriptionMapper->method('insert')->willReturnCallback(function (\OCA\Kanso\Db\Subscription $s) use (&$watchedBy): \OCA\Kanso\Db\Subscription {
			$watchedBy[] = $s->getSubscriber();
			return $s;
		});
		$items = [];
		$this->checklistItemMapper->method('insert')->willReturnCallback(function (\OCA\Kanso\Db\ChecklistItem $i) use (&$items): \OCA\Kanso\Db\ChecklistItem {
			$items[] = [$i->getTitle(), $i->getDone(), $i->getDueDate()?->getTimestamp(), $i->getAssignedUser(), $i->getAssignedRole(), $i->getDoneAt()];
			return $i;
		});

		// A change row on BOTH boards (target CREATE + source DELETE).
		$changeBoards = [];
		$this->changeNotifier->method('recordChange')->willReturnCallback(function (int $boardId, int $entity, int $cardId, int $action) use (&$changeBoards): Change {
			$changeBoards[] = [$boardId, $action];
			return new Change();
		});
		// Both boards pushed after commit.
		$pushed = [];
		$this->changeNotifier->method('pushBoardChanged')->willReturnCallback(function (int $boardId) use (&$pushed): void {
			$pushed[] = $boardId;
		});
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$moved = $this->service->moveToBoard(9, 7, 'alice');

		self::assertSame(42, $moved->getId());
		self::assertSame(2, $moved->getBoardId());
		self::assertSame(7, $moved->getStackId());
		self::assertSame('Ship it', $moved->getTitle(), 'move keeps the title (no " (copy)" suffix)');
		self::assertSame('the spec', $moved->getDescription());
		self::assertSame(88, $moved->getBoardSeq(), 'the KAN-id is re-issued on the target board');
		self::assertSame('3', $moved->getEstimate());
		self::assertSame([[42, 'bob']], $assignedTo, 'the readable assignee crosses over');
		self::assertSame(['carol'], $watchedBy, 'the readable watcher crosses over');
		self::assertSame(
			[['Step one', true, $stepDue->getTimestamp(), null, null, null]],
			$items,
			'the moved step keeps its due date; assignee, frozen role and done_at drop (#3745 clone policy)',
		);
		self::assertNotNull($softDeleted, 'the source card is soft-deleted');
		self::assertGreaterThan(0, $softDeleted->getDeletedAt());
		self::assertSame(
			[[2, Change::ACTION_CREATE], [1, Change::ACTION_DELETE]],
			$changeBoards,
			'a CREATE on the target board and a DELETE on the source board',
		);
		self::assertSame([2, 1], $pushed, 'both boards are pushed after commit');
	}

	/**
	 * moveToBoard needs EDIT on the TARGET board: a viewer with EDIT on the source
	 * but not the target is rejected, and the source is NOT soft-deleted (no
	 * half-move).
	 */
	public function testMoveToBoardAssertsEditOnTargetBoard(): void {
		$sourceBoard = $this->board(1);
		$targetBoard = $this->board(2);
		$targetStack = $this->stack(7, 2);
		$source = $this->card(9, 5, 1);

		$this->cardMapper->method('find')->willReturnMap([[9, $source]]);
		$this->stackMapper->method('find')->with(7)->willReturn($targetStack);
		$this->boardMapper->method('find')->willReturnMap([[1, $sourceBoard], [2, $targetBoard]]);

		$this->permissionService->method('assertPermission')
			->willReturnCallback(static function (Board $board, string $uid, int $perm): void {
				if ($board->getId() === 2) {
					throw new NotPermittedException();
				}
			});

		$this->cardMapper->expects(self::never())->method('insert');
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->moveToBoard(9, 7, 'bob');
	}

	/**
	 * A "move to board" whose target stack is on the SAME board is rejected (the
	 * in-board move() is the right tool) - and nothing is written.
	 */
	public function testMoveToBoardRejectsSameBoardTarget(): void {
		$board = $this->board(1);
		$targetStack = $this->stack(7, 1);
		$source = $this->card(9, 5, 1);

		$this->cardMapper->method('find')->willReturnMap([[9, $source]]);
		$this->stackMapper->method('find')->with(7)->willReturn($targetStack);
		$this->boardMapper->method('find')->with(1)->willReturn($board);

		$this->cardMapper->expects(self::never())->method('insert');
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->moveToBoard(9, 7, 'alice');
	}

	/**
	 * Cross-board move maps labels by title+color to the target board (unmatched
	 * drop) and DROPS any assignee/watcher who cannot READ the target board - the
	 * leak guard. Here 'bob' can read the target, 'mallory' cannot.
	 */
	public function testMoveToBoardMapsLabelsAndDropsUnreadableParticipants(): void {
		$sourceBoard = $this->board(1);
		$targetBoard = $this->board(2);
		$targetStack = $this->stack(7, 2);
		$source = $this->card(9, 5, 1);
		$source->setTitle('Cross move');

		$this->cardMapper->method('find')->willReturnMap([[9, $source]]);
		$this->stackMapper->method('find')->with(7)->willReturn($targetStack);
		$this->boardMapper->method('find')->willReturnMap([[1, $sourceBoard], [2, $targetBoard]]);
		$this->cardMapper->method('findLastInStack')->with(7)->willReturn(null);
		$this->cardMapper->method('nextBoardSeq')->with(2)->willReturn(5);
		$this->cardMapper->method('findChildren')->with(9)->willReturn([]);
		$this->cardMapper->method('insert')->willReturnCallback(static function (Card $c): Card {
			$c->setId(42);
			return $c;
		});
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());
		$this->checklistItemMapper->method('findByCard')->with(9)->willReturn([]);

		// 'bob' can read the target; 'mallory' cannot (no permission bits).
		$this->permissionService->method('getPermissions')
			->willReturnCallback(static fn (Board $b, string $uid): int => $uid === 'mallory' ? 0 : PermissionService::PERMISSION_ALL);

		$this->cardAssigneeMapper->method('findUserIdsByCard')->with(9)->willReturn(['bob', 'mallory']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(9)->willReturn(['bob', 'mallory']);

		// Source labels 11 (Bug/e01) + 12 (Secret/abc); target has only the Bug twin.
		$this->cardLabelMapper->method('findLabelIdsByCard')->with(9)->willReturn([11, 12]);
		$this->labelMapper->method('find')->willReturnMap([
			[11, $this->label(11, 1, 'Bug', 'e01e01')],
			[12, $this->label(12, 1, 'Secret', 'abcabc')],
		]);
		$this->labelMapper->method('findByBoard')->with(2)->willReturn([
			$this->label(71, 2, 'Bug', 'e01e01'),
		]);

		$labelAssignments = [];
		$this->cardLabelMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, int $labelId) use (&$labelAssignments): \OCA\Kanso\Db\CardLabel {
			$labelAssignments[] = $labelId;
			return new \OCA\Kanso\Db\CardLabel();
		});
		$assignees = [];
		$this->cardAssigneeMapper->method('insertAssignment')->willReturnCallback(function (int $cardId, string $uid) use (&$assignees): \OCA\Kanso\Db\CardAssignee {
			$assignees[] = $uid;
			return new \OCA\Kanso\Db\CardAssignee();
		});
		$watchers = [];
		$this->subscriptionMapper->method('insert')->willReturnCallback(function (\OCA\Kanso\Db\Subscription $s) use (&$watchers): \OCA\Kanso\Db\Subscription {
			$watchers[] = $s->getSubscriber();
			return $s;
		});

		$this->service->moveToBoard(9, 7, 'alice');

		self::assertSame([71], $labelAssignments, 'only the title+color twin is mapped; the unmatched label drops');
		self::assertSame(['bob'], $assignees, 'the unreadable assignee is dropped (leak guard)');
		self::assertSame(['bob'], $watchers, 'the unreadable watcher is dropped (leak guard)');
	}

	// ---- templates (#3409) ------------------------------------------------

	private function templateCard(int $id = 9, int $stackId = 5, int $boardId = 1): Card {
		$card = $this->card($id, $stackId, $boardId);
		$card->setIsTemplate(true);
		return $card;
	}

	public function testSetTemplateFlagsCardAndWritesChangeRow(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::once())
			->method('update')
			->with(self::callback(static fn (Card $c): bool => $c->getIsTemplate() === true))
			->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'alice')
			->willReturn(new Change());

		$updated = $this->service->setTemplate(9, true, 'alice');
		self::assertTrue($updated->getIsTemplate());
	}

	public function testSetTemplateUnflagsCard(): void {
		$this->cardMapper->method('find')->with(9)->willReturn($this->templateCard());
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$updated = $this->service->setTemplate(9, false, 'alice');
		self::assertFalse($updated->getIsTemplate());
	}

	public function testSetTemplateAssertsEditPermission(): void {
		$board = $this->board();
		$this->cardMapper->method('find')->with(9)->willReturn($this->card());
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');

		$this->expectException(NotPermittedException::class);
		$this->service->setTemplate(9, true, 'bob');
	}

	public function testListTemplatesAssertsReadPermissionAndReturnsTemplates(): void {
		$board = $this->board();
		$templates = [$this->templateCard(9), $this->templateCard(10)];
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_READ);
		$this->cardMapper->expects(self::once())
			->method('findTemplatesByBoard')
			->with(1, self::isInstanceOf(ViewerContext::class))
			->willReturn($templates);

		self::assertSame($templates, $this->service->listTemplates(1, 'alice'));
	}

	public function testListTemplatesDeniedForNonReader(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('findTemplatesByBoard');

		$this->expectException(NotPermittedException::class);
		$this->service->listTemplates(1, 'bob');
	}

	/**
	 * create-from-template clones EXACTLY title / description / labels / checklist
	 * (+ priority / type / status / estimate) into a fresh LIVE card; the new card
	 * is never itself a template, and comments/assignees/history are never touched.
	 */
	public function testCreateFromTemplateClonesContentIntoFreshLiveCard(): void {
		$board = $this->board(1);
		$board->setEstimateScale('fibonacci');
		$targetStack = $this->stack(7, 1);

		$template = $this->templateCard(9, 5, 1);
		$template->setTitle('Bug report template');
		$template->setDescription('## Steps to reproduce');
		$template->setPriority(Card::PRIORITY_URGENT);
		$template->setType('bug');
		$template->setEstimate('3');

		$this->cardMapper->method('find')->willReturnMap([[9, $template]]);
		$this->stackMapper->method('find')->with(7)->willReturn($targetStack);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('findLastInStack')->with(7)->willReturn(null);

		$this->cardMapper->method('insert')->willReturnCallback(static function (Card $c): Card {
			$c->setId(42);
			return $c;
		});
		$this->cardMapper->method('update')->willReturnCallback(static fn (Card $c): Card => $c);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		// Same board → source label ids re-assigned directly.
		$this->cardLabelMapper->method('findLabelIdsByCard')->with(9)->willReturn([11, 12]);
		$assigned = [];
		$this->labelService->method('assign')->willReturnCallback(function (int $cardId, int $labelId) use (&$assigned): void {
			$assigned[] = [$cardId, $labelId];
		});

		$this->checklistItemMapper->method('findByCard')->with(9)->willReturn([
			$this->checklistItem(1, 9, 'Reproduce', false),
			$this->checklistItem(2, 9, 'Attach logs', true),
		]);
		$added = [];
		$this->checklistService->method('addItem')->willReturnCallback(function (int $cardId, string $title, string $uid, bool $done) use (&$added): \OCA\Kanso\Db\ChecklistItem {
			$added[] = [$title, $done];
			$item = new \OCA\Kanso\Db\ChecklistItem();
			$item->setId(count($added) + 100);
			return $item;
		});

		$card = $this->service->createFromTemplate(9, 7, 'alice');

		self::assertSame(42, $card->getId());
		// Title is cloned verbatim (no " (copy)" suffix - this is a fresh card).
		self::assertSame('Bug report template', $card->getTitle());
		self::assertSame('## Steps to reproduce', $card->getDescription());
		self::assertSame(Card::PRIORITY_URGENT, $card->getPriority());
		self::assertSame('bug', $card->getType());
		self::assertSame('3', $card->getEstimate());
		// The new card is a LIVE card, never a template.
		self::assertFalse($card->getIsTemplate());
		self::assertSame([[42, 11], [42, 12]], $assigned);
		self::assertSame([['Reproduce', false], ['Attach logs', true]], $added);
	}

	public function testCreateFromTemplateRejectsNonTemplateCard(): void {
		// A plain (non-template) card cannot back a create-from-template.
		$plain = $this->card(9, 5, 1); // isTemplate defaults false
		$this->cardMapper->method('find')->willReturnMap([[9, $plain]]);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->createFromTemplate(9, 7, 'alice');
	}

	public function testCreateFromTemplateRejectsTargetStackOnAnotherBoard(): void {
		// Templates are per-board: a template cannot spawn a card on a stack that
		// belongs to a different board.
		$template = $this->templateCard(9, 5, 1);
		$otherBoardStack = $this->stack(7, 2);
		$this->cardMapper->method('find')->willReturnMap([[9, $template]]);
		$this->stackMapper->method('find')->with(7)->willReturn($otherBoardStack);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1));
		$this->cardMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->createFromTemplate(9, 7, 'alice');
	}

	public function testCreateFromTemplateAssertsEditPermission(): void {
		$board = $this->board();
		$template = $this->templateCard(9, 5, 1);
		$this->cardMapper->method('find')->willReturnMap([[9, $template]]);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->createFromTemplate(9, 7, 'bob');
	}

	public function testIsTemplateSerializedInSummaryAndDetail(): void {
		$card = $this->templateCard();

		$summary = $card->jsonSerializeSummary();
		self::assertArrayHasKey('isTemplate', $summary);
		self::assertTrue($summary['isTemplate']);

		$detail = $card->jsonSerialize();
		self::assertTrue($detail['isTemplate']);

		// A plain card serializes false.
		self::assertFalse($this->card()->jsonSerializeSummary()['isTemplate']);
	}

	// ---- rebalanceStack (409 rebalance_required recovery) -----------------

	public function testRebalanceStackRewritesToShortStrictlyIncreasingKeysOrderPreserved(): void {
		// A stack whose keys have grown pathologically long (the overflow case).
		$long = str_repeat('I', 40);
		$cards = [
			$this->card(9, 5, 1, $long . 'A'),
			$this->card(10, 5, 1, $long . 'B'),
			$this->card(11, 5, 1, $long . 'C'),
		];
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5));
		$this->cardMapper->expects(self::once())
			->method('findByStackForUpdate')
			->with(5)
			->willReturn($cards);

		// Capture every sort-key write in order (pass 1 temp keys + pass 2 finals).
		$writes = [];
		$this->cardMapper->method('updateSortKeyById')
			->willReturnCallback(static function (int $id, string $key) use (&$writes): void {
				$writes[] = [$id, $key];
			});

		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_STACK, 5, Change::ACTION_MOVE, null, Change::VERB_MOVED)
			->willReturn(new Change());
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$count = $this->service->rebalanceStack(5);
		self::assertSame(3, $count);

		// Two passes of 3 writes each.
		self::assertCount(6, $writes);

		// Pass 1 parks each row at a distinct three-character temporary key,
		// disjoint from the current keys and (by length) from the finals, so no
		// write collides with a not-yet-rewritten row.
		$sortKeyService = new SortKeyService();
		$oldKeys = [$long . 'A', $long . 'B', $long . 'C'];
		$tempWrites = array_slice($writes, 0, 3);
		$tempKeys = array_map(static fn (array $w): string => $w[1], $tempWrites);
		self::assertSame([9, 10, 11], array_map(static fn (array $w): int => $w[0], $tempWrites));
		self::assertSame($tempKeys, array_unique($tempKeys), 'temp keys must be distinct');
		self::assertSame([], array_intersect($tempKeys, $oldKeys), 'temp keys must not reuse a current key');
		foreach ($tempKeys as $k) {
			self::assertSame(3, strlen($k), 'temp keys are three characters');
			self::assertMatchesRegularExpression('/^[0-9A-Z]{3}$/', $k);
		}

		// Pass 2 writes the final evenly-spaced keys, same row order preserved.
		$finalWrites = array_slice($writes, 3, 3);
		self::assertSame([9, 10, 11], array_map(static fn (array $w): int => $w[0], $finalWrites));
		$finalKeys = array_map(static fn (array $w): string => $w[1], $finalWrites);

		// Short, strictly increasing, and no final key collides with a temp key.
		for ($i = 0; $i < 3; $i++) {
			self::assertLessThanOrEqual(SortKeyService::MAX_KEY_LENGTH, strlen($finalKeys[$i]));
			self::assertStringEndsNotWith('0', $finalKeys[$i]);
			if ($i > 0) {
				self::assertLessThan(0, strcmp($finalKeys[$i - 1], $finalKeys[$i]));
			}
		}
		self::assertSame([], array_intersect($finalKeys, $tempKeys));

		// The recovery invariant: a between() at any gap of the fresh keys no
		// longer overflows (the very thing the 409 could not do before).
		$mid = $sortKeyService->between($finalKeys[0], $finalKeys[1]);
		self::assertLessThan(0, strcmp($finalKeys[0], $mid));
		self::assertLessThan(0, strcmp($mid, $finalKeys[1]));
	}

	public function testRebalanceStackOnEmptyStackIsANoOpButStillCommits(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5));
		$this->cardMapper->method('findByStackForUpdate')->with(5)->willReturn([]);
		$this->cardMapper->expects(self::never())->method('updateSortKeyById');
		$this->changeNotifier->expects(self::never())->method('recordChange');
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');

		self::assertSame(0, $this->service->rebalanceStack(5));
	}

	public function testRebalanceStackRollsBackOnWriteFailure(): void {
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5));
		$this->cardMapper->method('findByStackForUpdate')->with(5)
			->willReturn([$this->card(9, 5, 1, 'I')]);
		$this->cardMapper->method('updateSortKeyById')
			->willThrowException(new \RuntimeException('boom'));
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');

		$this->expectException(\RuntimeException::class);
		$this->service->rebalanceStack(5);
	}

	public function testRebalanceStackRejectsDeletedStack(): void {
		$stack = $this->stack(5);
		$stack->setDeletedAt(123);
		$this->stackMapper->method('find')->with(5)->willReturn($stack);
		$this->db->expects(self::never())->method('beginTransaction');

		$this->expectException(DoesNotExistException::class);
		$this->service->rebalanceStack(5);
	}

	public function testRebalanceBoardRebalancesEveryStack(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board(1));
		$this->stackMapper->method('findByBoard')->with(1)
			->willReturn([$this->stack(5, 1), $this->stack(6, 1)]);
		$this->stackMapper->method('find')->willReturnCallback(fn (int $id): Stack => $this->stack($id, 1));
		$this->cardMapper->method('findByStackForUpdate')->willReturnCallback(
			fn (int $stackId): array => $stackId === 5
				? [$this->card(9, 5, 1, 'I'), $this->card(10, 5, 1, 'J')]
				: [$this->card(11, 6, 1, 'I')]
		);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		$result = $this->service->rebalanceBoard(1);
		self::assertSame([5 => 2, 6 => 1], $result);
	}
}

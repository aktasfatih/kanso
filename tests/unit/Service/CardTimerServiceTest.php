<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\CardRunningTimer;
use OCA\Kanso\Db\CardRunningTimerMapper;
use OCA\Kanso\Db\CardTimeEntryMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Service\CardTimerService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Tests\Stubs\CardMutationService as MutationScope;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CardTimerServiceTest extends TestCase {
	private CardRunningTimerMapper&MockObject $runningTimerMapper;
	private CardTimeEntryMapper&MockObject $timeEntryMapper;
	private CardMapper&MockObject $cardMapper;
	private BoardMapper&MockObject $boardMapper;
	private PermissionService&MockObject $permissions;
	private CardVisibilityGuard&MockObject $visibility;
	private ChangeNotifier&MockObject $notifier;
	private IDBConnection&MockObject $db;
	private ITimeFactory&MockObject $clock;
	private CardTimerService $service;
	private MutationScope $mutation;

	protected function setUp(): void {
		parent::setUp();
		$this->runningTimerMapper = $this->createMock(CardRunningTimerMapper::class);
		$this->timeEntryMapper = $this->createMock(CardTimeEntryMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->visibility = $this->createMock(CardVisibilityGuard::class);
		$this->notifier = $this->createMock(ChangeNotifier::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->clock = $this->createMock(ITimeFactory::class);
		$this->clock->method('getTime')->willReturn(1090);
		$this->mutation = new MutationScope($this->db, true);
		$this->service = new CardTimerService(
			$this->runningTimerMapper,
			$this->timeEntryMapper,
			$this->cardMapper,
			$this->boardMapper,
			$this->permissions,
			$this->visibility,
			$this->notifier,
			$this->mutation,
			$this->clock,
		);
	}

	private function loadCard(bool $allowAiActors = false): Board {
		$card = new Card();
		$card->setId(9);
		$card->setBoardId(1);
		$card->setDeletedAt(0);
		$board = new Board();
		$board->setId(1);
		$board->setDeletedAt(0);
		$board->setAllowAiActors($allowAiActors);
		$this->cardMapper->method('find')->with(9)->willReturn($card);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		return $board;
	}

	private function timer(int $startedAt = 1000): CardRunningTimer {
		$timer = new CardRunningTimer();
		$timer->setId(3);
		$timer->setCardId(9);
		$timer->setBoardId(1);
		$timer->setStartedBy('alice');
		$timer->setStartedAt($startedAt);
		$timer->setActorName('Codex');
		return $timer;
	}

	public function testStartRequiresEditPermissionBeforeLookingUpATimer(): void {
		$board = $this->loadCard();
		$this->permissions->expects(self::once())->method('assertPermission')
			->with($board, 'reader', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->runningTimerMapper->expects(self::never())->method('findByCard');
		$this->expectException(NotPermittedException::class);
		$this->service->start(9, 'reader');
	}

	public function testStopRequiresEditPermissionBeforeLookingUpATimer(): void {
		$board = $this->loadCard();
		$this->permissions->expects(self::once())->method('assertPermission')
			->with($board, 'reader', PermissionService::PERMISSION_EDIT)
			->willThrowException(new NotPermittedException());
		$this->runningTimerMapper->expects(self::never())->method('findByCard');
		$this->expectException(NotPermittedException::class);
		$this->service->stop(9, 'reader');
	}

	public function testStartCannotReachAHiddenCard(): void {
		$this->loadCard();
		$this->visibility->expects(self::once())->method('assertVisible')
			->willThrowException(new DoesNotExistException('hidden'));
		$this->runningTimerMapper->expects(self::never())->method('findByCard');
		$this->expectException(DoesNotExistException::class);
		$this->service->start(9, 'bob');
	}

	public function testStopCannotReachAHiddenCard(): void {
		$this->loadCard();
		$this->visibility->expects(self::once())->method('assertVisible')
			->willThrowException(new DoesNotExistException('hidden'));
		$this->runningTimerMapper->expects(self::never())->method('findByCard');
		$this->expectException(DoesNotExistException::class);
		$this->service->stop(9, 'bob');
	}

	public function testStartRecordsActorAndChangeAtomically(): void {
		$this->loadCard(true);
		$this->runningTimerMapper->method('findByCard')->willThrowException(new DoesNotExistException('none'));
		$this->runningTimerMapper->expects(self::once())->method('insert')->willReturnCallback(
			function (CardRunningTimer $timer): CardRunningTimer {
				self::assertSame(9, $timer->getCardId());
				self::assertSame(1, $timer->getBoardId());
				self::assertSame('bob', $timer->getStartedBy());
				self::assertSame('Codex', $timer->getActorName());
				self::assertSame(1090, $timer->getStartedAt());
				$timer->setId(3);
				return $timer;
			}
		);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');
		$this->notifier->expects(self::once())->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob');
		$this->notifier->expects(self::once())->method('pushBoardChanged')->with(1);

		self::assertSame(3, $this->service->start(9, 'bob', '  Codex  ')->getId());
	}

	public function testStartRejectsAiAttributionWhenBoardDisallowsIt(): void {
		$this->loadCard();
		$this->runningTimerMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->start(9, 'bob', 'Codex');
	}

	public function testStartRejectsAnActorNameLongerThanTheColumn(): void {
		$this->loadCard(true);
		$this->runningTimerMapper->expects(self::never())->method('insert');
		$this->expectException(InvalidInputException::class);
		$this->service->start(9, 'bob', str_repeat('é', 256));
	}

	public function testStartReturnsExistingTimerWithoutChangingItsAttribution(): void {
		$this->loadCard(true);
		$timer = $this->timer();
		$this->runningTimerMapper->method('findByCard')->willReturn($timer);
		$this->runningTimerMapper->expects(self::never())->method('insert');
		$this->db->expects(self::once())->method('beginTransaction');

		self::assertSame($timer, $this->service->start(9, 'bob', 'Another actor'));
	}

	public function testStartReadsRunningStateWithinTheMutationBoundary(): void {
		$this->loadCard();
		$timer = $this->timer();
		$inside = false;
		$this->db->expects(self::once())->method('beginTransaction')->willReturnCallback(static function () use (&$inside): void {
			$inside = true;
		});
		$this->runningTimerMapper->method('findByCard')->willReturnCallback(
			static function () use (&$inside, $timer): CardRunningTimer {
				self::assertTrue($inside);
				return $timer;
			}
		);
		$this->runningTimerMapper->expects(self::never())->method('insert');
		self::assertSame($timer, $this->service->start(9, 'bob'));
	}

	public function testStopCreditsTheStarterAndAiActorInsteadOfTheStoppingEditor(): void {
		$this->loadCard();
		$this->runningTimerMapper->method('findByCard')->willReturn($this->timer());
		$this->runningTimerMapper->expects(self::once())->method('deleteIfCurrent')->with(3, 9)->willReturn(1);
		$this->timeEntryMapper->expects(self::once())->method('insert')->willReturnArgument(0);
		$this->db->expects(self::once())->method('commit');
		$this->notifier->expects(self::once())->method('recordChange')
			->with(1, Change::ENTITY_CARD, 9, Change::ACTION_UPDATE, 'bob');
		$this->notifier->expects(self::once())->method('pushBoardChanged')->with(1);

		$entry = $this->service->stop(9, 'bob');
		self::assertNotNull($entry);
		self::assertSame(90, $entry->getSeconds());
		self::assertSame('alice', $entry->getCreatedBy());
		self::assertSame('Codex', $entry->getActorName());
		self::assertSame('Tracked with timer', $entry->getNote());
		self::assertSame(1090, $entry->getCreatedAt());
	}

	public function testConcurrentStopDoesNotRecordTheSameTimerTwice(): void {
		$this->loadCard();
		$this->runningTimerMapper->method('findByCard')->willReturn($this->timer());
		$this->runningTimerMapper->method('deleteIfCurrent')->with(3, 9)->willReturn(0);
		$this->timeEntryMapper->expects(self::never())->method('insert');
		$this->notifier->expects(self::never())->method('recordChange');
		$this->notifier->expects(self::never())->method('pushBoardChanged');
		$this->db->expects(self::once())->method('commit');

		self::assertNull($this->service->stop(9, 'bob'));
	}

	public function testStopWithoutATimerDoesNotWriteOrNotify(): void {
		$this->loadCard();
		$this->runningTimerMapper->method('findByCard')->willThrowException(new DoesNotExistException('none'));
		$this->db->expects(self::once())->method('beginTransaction');
		$this->timeEntryMapper->expects(self::never())->method('insert');
		$this->notifier->expects(self::never())->method('recordChange');
		self::assertNull($this->service->stop(9, 'bob'));
	}

	public function testZeroElapsedStopStillClearsTheRunningIndicator(): void {
		$this->loadCard();
		$this->runningTimerMapper->method('findByCard')->willReturn($this->timer(1090));
		$this->runningTimerMapper->expects(self::once())->method('deleteIfCurrent')->willReturn(1);
		$this->timeEntryMapper->expects(self::never())->method('insert');
		$this->notifier->expects(self::once())->method('recordChange');
		$this->notifier->expects(self::once())->method('pushBoardChanged');
		self::assertNull($this->service->stop(9, 'bob'));
	}

	public function testNestedTimerMutationDoesNotPushBeforeTheOuterCommit(): void {
		$this->loadCard();
		$this->runningTimerMapper->method('findByCard')->willThrowException(new DoesNotExistException('none'));
		$this->runningTimerMapper->method('insert')->willReturnArgument(0);
		$pushed = false;
		$this->notifier->expects(self::once())->method('pushBoardChanged')->willReturnCallback(static function () use (&$pushed): void {
			$pushed = true;
		});
		$this->mutation->runForBoards([1], function () use (&$pushed): void {
			$this->service->start(9, 'bob');
			self::assertFalse($pushed);
		});
		self::assertTrue($pushed);
	}

	public function testFailedEntryWriteRollsBackTheTimerClaimAndDoesNotPush(): void {
		$this->loadCard();
		$this->runningTimerMapper->method('findByCard')->willReturn($this->timer());
		$this->runningTimerMapper->method('deleteIfCurrent')->willReturn(1);
		$this->timeEntryMapper->method('insert')->willThrowException(new \RuntimeException('storage failed'));
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->notifier->expects(self::never())->method('pushBoardChanged');
		$this->expectException(\RuntimeException::class);
		$this->service->stop(9, 'bob');
	}

	public function testFailedStartChangeRollsBackTheTimerInsertAndDoesNotPush(): void {
		$this->loadCard();
		$this->runningTimerMapper->method('findByCard')->willThrowException(new DoesNotExistException('none'));
		$this->runningTimerMapper->method('insert')->willReturnArgument(0);
		$this->notifier->method('recordChange')->willThrowException(new \RuntimeException('log failed'));
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		$this->notifier->expects(self::never())->method('pushBoardChanged');
		$this->expectException(\RuntimeException::class);
		$this->service->start(9, 'bob');
	}
}

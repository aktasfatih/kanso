<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\ArchiveRule;
use OCA\Kanso\Db\ArchiveRuleMapper;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\ArchiveService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ArchiveServiceTest extends TestCase {
	private const NOW = 1_800_000_000;

	private ArchiveRuleMapper&MockObject $ruleMapper;
	private CardMapper&MockObject $cardMapper;
	private StackMapper&MockObject $stackMapper;
	private BoardMapper&MockObject $boardMapper;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private ITimeFactory&MockObject $time;
	private IDBConnection&MockObject $db;
	private ArchiveService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->ruleMapper = $this->createMock(ArchiveRuleMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new ArchiveService(
			$this->ruleMapper,
			$this->cardMapper,
			$this->stackMapper,
			$this->boardMapper,
			$this->changeNotifier,
			$this->permissionService,
			$this->time,
			$this->db,
		);
	}

	private function board(int $id = 1): Board {
		$board = new Board();
		$board->setId($id);
		$board->setOwner('alice');
		$board->setDeletedAt(0);
		return $board;
	}

	private function stack(int $id = 5, int $boardId = 1): Stack {
		$stack = new Stack();
		$stack->setId($id);
		$stack->setBoardId($boardId);
		$stack->setDeletedAt(0);
		return $stack;
	}

	private function rule(
		int $id = 3,
		int $boardId = 1,
		?int $stackId = null,
		int $condition = ArchiveRule::CONDITION_DONE_FOR,
		int $threshold = 86400,
	): ArchiveRule {
		$rule = new ArchiveRule();
		$rule->setId($id);
		$rule->setBoardId($boardId);
		$rule->setStackId($stackId);
		$rule->setCondition($condition);
		$rule->setThresholdSeconds($threshold);
		$rule->setEnabled(true);
		$rule->setCreatedAt(self::NOW);
		return $rule;
	}

	private function card(int $id, int $doneAt = self::NOW): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId(1);
		$card->setStackId(5);
		$card->setDoneAt($doneAt);
		$card->setArchived(false);
		return $card;
	}

	// ---- create -----------------------------------------------------------

	public function testCreateRequiresManageAndPersists(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->ruleMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (ArchiveRule $r): ArchiveRule {
				self::assertSame(1, $r->getBoardId());
				self::assertNull($r->getStackId());
				self::assertSame(ArchiveRule::CONDITION_DONE_FOR, $r->getCondition());
				self::assertSame(86400, $r->getThresholdSeconds());
				self::assertTrue($r->getEnabled());
				self::assertSame(self::NOW, $r->getCreatedAt());
				$r->setId(9);
				return $r;
			});

		$rule = $this->service->create(1, null, ArchiveRule::CONDITION_DONE_FOR, 86400, 'alice');
		self::assertSame(9, $rule->getId());
	}

	public function testCreateWithoutManageThrows403AndDoesNotPersist(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('assertPermission')
			->with($board, 'bob', PermissionService::PERMISSION_MANAGE)
			->willThrowException(new NotPermittedException());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, null, ArchiveRule::CONDITION_DONE_FOR, 86400, 'bob');
	}

	public function testCreateValidatesStackBelongsToBoard(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// Stack 5 lives on board 2, not 1.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5, 2));
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 5, ArchiveRule::CONDITION_DONE_FOR, 86400, 'alice');
	}

	public function testCreateRejectsInvalidCondition(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, null, 99, 86400, 'alice');
	}

	public function testCreateRejectsNegativeThreshold(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, null, ArchiveRule::CONDITION_DONE_FOR, -1, 'alice');
	}

	// ---- update -----------------------------------------------------------

	public function testUpdateAppliesFieldsWithManage(): void {
		$rule = $this->rule();
		$board = $this->board();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$updated = $this->service->update(3, null, false, ArchiveRule::CONDITION_DONE_AND_AGE, 3600, false, 'alice');
		self::assertSame(ArchiveRule::CONDITION_DONE_AND_AGE, $updated->getCondition());
		self::assertSame(3600, $updated->getThresholdSeconds());
		self::assertFalse($updated->getEnabled());
	}

	public function testUpdateClearsStackWhenProvidedNull(): void {
		$rule = $this->rule(stackId: 5);
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$updated = $this->service->update(3, null, true, null, null, null, 'alice');
		self::assertNull($updated->getStackId());
	}

	public function testUpdateWithoutManageThrows403(): void {
		$rule = $this->rule();
		$board = $this->board();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->ruleMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->update(3, null, false, null, 3600, null, 'bob');
	}

	// ---- delete -----------------------------------------------------------

	public function testDeleteRequiresManage(): void {
		$rule = $this->rule();
		$board = $this->board();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->ruleMapper->expects(self::once())->method('delete')->with($rule)->willReturnArgument(0);

		$this->service->delete(3, 'alice');
	}

	public function testDeleteWithoutManageThrows403(): void {
		$rule = $this->rule();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->ruleMapper->expects(self::never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->delete(3, 'bob');
	}

	// ---- listForBoard -----------------------------------------------------

	public function testListForBoardRequiresRead(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_READ);
		$this->ruleMapper->expects(self::once())->method('findByBoard')->with(1)->willReturn([$this->rule()]);

		$rules = $this->service->listForBoard(1, 'alice');
		self::assertCount(1, $rules);
	}

	public function testListForBoardWithoutReadThrows403(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->ruleMapper->expects(self::never())->method('findByBoard');

		$this->expectException(NotPermittedException::class);
		$this->service->listForBoard(1, 'bob');
	}

	// ---- findEligibleCards: predicate shape -------------------------------

	public function testFindEligibleCardsDoneForCondition(): void {
		$rule = $this->rule(condition: ArchiveRule::CONDITION_DONE_FOR, threshold: 86400);
		$this->cardMapper->expects(self::once())
			->method('findEligibleForArchive')
			->with(1, null, ArchiveRule::CONDITION_DONE_FOR, self::NOW - 86400, ArchiveService::MAX_PER_SWEEP)
			->willReturn([$this->card(10)]);

		$cards = $this->service->findEligibleCards($rule);
		self::assertCount(1, $cards);
	}

	public function testFindEligibleCardsDoneAndAgeConditionScopedToStack(): void {
		$rule = $this->rule(stackId: 5, condition: ArchiveRule::CONDITION_DONE_AND_AGE, threshold: 3600);
		$this->cardMapper->expects(self::once())
			->method('findEligibleForArchive')
			->with(1, 5, ArchiveRule::CONDITION_DONE_AND_AGE, self::NOW - 3600, ArchiveService::MAX_PER_SWEEP)
			->willReturn([]);

		self::assertSame([], $this->service->findEligibleCards($rule));
	}

	// ---- sweep ------------------------------------------------------------

	/**
	 * #3418 + #3579: a batch sweep records ONE change row per archived card (so no
	 * client delta is lost) but coalesces the realtime fan-out into a SINGLE
	 * pushBoardChanged for the whole batch (the push body is only {boardId}, so
	 * one push suffices - clients delta/collapse the N rows). Each card's archive
	 * write + its change row commit atomically (a per-item transaction).
	 */
	public function testSweepRecordsPerCardChangeButEmitsOneCoalescedPush(): void {
		$rule = $this->rule();
		$this->cardMapper->method('findEligibleForArchive')
			->willReturn([$this->card(10), $this->card(11)]);

		$archived = [];
		$this->cardMapper->expects(self::exactly(2))
			->method('update')
			->willReturnCallback(function (Card $c) use (&$archived): Card {
				self::assertTrue($c->getArchived());
				$archived[] = $c->getId();
				return $c;
			});

		// N change rows recorded (never the push-emitting notify()).
		$notified = [];
		$this->changeNotifier->expects(self::exactly(2))
			->method('recordChange')
			->willReturnCallback(function (int $boardId, int $entity, int $entityId, int $action, ?string $actor) use (&$notified): Change {
				self::assertSame(1, $boardId);
				self::assertSame(Change::ENTITY_CARD, $entity);
				self::assertSame(Change::ACTION_UPDATE, $action);
				self::assertNull($actor);
				$notified[] = $entityId;
				return new Change();
			});
		$this->changeNotifier->expects(self::never())->method('notify');
		// Exactly ONE coalesced push for the whole batch, not one per card.
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);
		// Each card is committed in its own transaction.
		$this->db->expects(self::exactly(2))->method('beginTransaction');
		$this->db->expects(self::exactly(2))->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$count = $this->service->sweep($rule);
		self::assertSame(2, $count);
		self::assertSame([10, 11], $archived);
		self::assertSame([10, 11], $notified);
	}

	public function testSweepWithNoEligibleCardsIsNoOp(): void {
		$rule = $this->rule();
		$this->cardMapper->method('findEligibleForArchive')->willReturn([]);
		$this->cardMapper->expects(self::never())->method('update');
		$this->changeNotifier->expects(self::never())->method('recordChange');
		// An empty sweep emits no push at all.
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');
		$this->db->expects(self::never())->method('beginTransaction');

		self::assertSame(0, $this->service->sweep($rule));
	}

	/**
	 * #3579: if a card's change-row write throws mid-sweep, that card's archive
	 * write rolls back (per-item transaction) rather than leaving it archived
	 * without a delta row.
	 */
	public function testSweepRollsBackCardWhenChangeRowInsertThrows(): void {
		$rule = $this->rule();
		$this->cardMapper->method('findEligibleForArchive')->willReturn([$this->card(10)]);
		$this->cardMapper->expects(self::once())->method('update')->willReturnArgument(0);
		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->willThrowException(new \RuntimeException('change row insert failed'));

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		// A failed item aborts the sweep before the coalesced push.
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');

		$this->expectException(\RuntimeException::class);
		$this->service->sweep($rule);
	}

	// ---- archiveNow -------------------------------------------------------

	public function testArchiveNowRequiresManageAndReturnsCount(): void {
		$rule = $this->rule();
		$board = $this->board();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->cardMapper->method('findEligibleForArchive')->willReturn([$this->card(10)]);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		self::assertSame(1, $this->service->archiveNow(3, 'alice'));
	}

	public function testArchiveNowWithoutManageThrows403(): void {
		$rule = $this->rule();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->cardMapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->archiveNow(3, 'bob');
	}

	// ---- runEnabledRules (cron entry) -------------------------------------

	public function testRunEnabledRulesSweepsEachAndSumsCounts(): void {
		$ruleA = $this->rule(id: 1);
		$ruleB = $this->rule(id: 2, boardId: 1);
		$this->ruleMapper->method('findAllEnabled')->willReturn([$ruleA, $ruleB]);
		$this->cardMapper->method('findEligibleForArchive')
			->willReturnOnConsecutiveCalls([$this->card(10)], [$this->card(11), $this->card(12)]);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		self::assertSame(3, $this->service->runEnabledRules());
	}

	public function testRunEnabledRulesSkipsBrokenRule(): void {
		$ruleA = $this->rule(id: 1);
		$ruleB = $this->rule(id: 2);
		$this->ruleMapper->method('findAllEnabled')->willReturn([$ruleA, $ruleB]);
		// First rule's sweep blows up; the second must still run.
		$this->cardMapper->method('findEligibleForArchive')
			->willReturnCallback(function (int $boardId, ?int $stackId, int $condition, int $cutoff, int $limit): array {
				static $call = 0;
				$call++;
				if ($call === 1) {
					throw new DoesNotExistException('board gone');
				}
				return [$this->card(11)];
			});
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->changeNotifier->method('recordChange')->willReturn(new Change());

		self::assertSame(1, $this->service->runEnabledRules());
	}
}

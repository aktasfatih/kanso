<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardLabelMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\RecurRule;
use OCA\Kanso\Db\RecurRuleMapper;
use OCA\Kanso\Db\Stack;
use OCA\Kanso\Db\StackMapper;
use OCA\Kanso\Service\CardService;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\RecurrenceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecurrenceServiceTest extends TestCase {
	private const NOW = 1_800_000_000;

	private RecurRuleMapper&MockObject $ruleMapper;
	private CardMapper&MockObject $cardMapper;
	private StackMapper&MockObject $stackMapper;
	private BoardMapper&MockObject $boardMapper;
	private CardLabelMapper&MockObject $cardLabelMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private CardService&MockObject $cardService;
	private ChangeNotifier&MockObject $changeNotifier;
	private PermissionService&MockObject $permissionService;
	private ITimeFactory&MockObject $time;
	private IDBConnection&MockObject $db;
	private LoggerInterface&MockObject $logger;
	private RecurrenceService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->ruleMapper = $this->createMock(RecurRuleMapper::class);
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->stackMapper = $this->createMock(StackMapper::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		$this->cardLabelMapper = $this->createMock(CardLabelMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->cardService = $this->createMock(CardService::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new RecurrenceService(
			$this->ruleMapper,
			$this->cardMapper,
			$this->stackMapper,
			$this->boardMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->cardService,
			$this->changeNotifier,
			$this->permissionService,
			$this->time,
			$this->db,
			$this->logger,
		);
	}

	// ---- fixtures ---------------------------------------------------------

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

	private function templateCard(int $id = 10, int $boardId = 1): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId(4);
		$card->setTitle('Water the plants');
		$card->setDescription('- [ ] living room');
		$card->setDoneAt(0);
		$card->setArchived(false);
		$card->setDeletedAt(0);
		return $card;
	}

	private function rule(
		int $id = 3,
		int $mode = RecurRule::MODE_CLONE,
		string $rrule = 'FREQ=DAILY',
		int $policy = RecurRule::POLICY_AT_OCCURRENCE,
		int $offset = 0,
		bool $skipWhileOpen = false,
		int $nextOccurrenceAt = self::NOW,
		int $lastSpawnedAt = 0,
		int $occurrencesSpawned = 0,
	): RecurRule {
		$rule = new RecurRule();
		$rule->setId($id);
		$rule->setBoardId(1);
		$rule->setTemplateCardId(10);
		$rule->setTargetStackId(5);
		$rule->setMode($mode);
		$rule->setRrule($rrule);
		$rule->setDuedatePolicy($policy);
		$rule->setDuedateOffsetSeconds($offset);
		$rule->setSkipWhileOpen($skipWhileOpen);
		$rule->setEnabled(true);
		$rule->setOwner('alice');
		$rule->setLastSpawnedAt($lastSpawnedAt);
		$rule->setNextOccurrenceAt($nextOccurrenceAt);
		$rule->setOccurrencesSpawned($occurrencesSpawned);
		$rule->setCreatedAt(self::NOW);
		return $rule;
	}

	private function spawnedCard(int $id = 99): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId(1);
		$card->setStackId(5);
		$card->setTitle('Water the plants');
		$card->setDoneAt(0);
		$card->setArchived(false);
		$card->setDeletedAt(0);
		return $card;
	}

	// ---- computeNextOccurrence -------------------------------------------

	public function testComputeNextOccurrenceDaily(): void {
		$start = self::NOW; // anchor
		$next = $this->service->computeNextOccurrence('FREQ=DAILY', $start, $start);
		// First occurrence strictly after the anchor is one day later.
		self::assertSame($start + 86400, $next);
	}

	public function testComputeNextOccurrenceWeekly(): void {
		$start = self::NOW;
		$next = $this->service->computeNextOccurrence('FREQ=WEEKLY', $start, $start);
		self::assertSame($start + 7 * 86400, $next);
	}

	public function testComputeNextOccurrenceMonthly(): void {
		$anchor = (new \DateTimeImmutable('2026-01-15T00:00:00Z'))->getTimestamp();
		$next = $this->service->computeNextOccurrence('FREQ=MONTHLY', $anchor, $anchor);
		self::assertSame((new \DateTimeImmutable('2026-02-15T00:00:00Z'))->getTimestamp(), $next);
	}

	public function testComputeNextOccurrenceReturnsAtOrAfterWhenNotYetFired(): void {
		$anchor = self::NOW;
		// Ask for the next occurrence after a time just before the anchor:
		// the anchor itself is the first occurrence.
		$next = $this->service->computeNextOccurrence('FREQ=DAILY', $anchor - 1, $anchor);
		self::assertSame($anchor, $next);
	}

	public function testComputeNextOccurrenceCountExhaustionReturnsZero(): void {
		$anchor = self::NOW;
		// COUNT=3 → occurrences at anchor, +1d, +2d. After the 3rd, exhausted.
		$next = $this->service->computeNextOccurrence('FREQ=DAILY;COUNT=3', $anchor + 2 * 86400, $anchor);
		self::assertSame(0, $next);
	}

	public function testComputeNextOccurrenceUntilExhaustionReturnsZero(): void {
		$anchor = (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp();
		$until = (new \DateTimeImmutable('2026-01-03T00:00:00Z'))->getTimestamp();
		// After the UNTIL bound, no more occurrences.
		$next = $this->service->computeNextOccurrence('FREQ=DAILY;UNTIL=20260103T000000Z', $until, $anchor);
		self::assertSame(0, $next);
	}

	public function testComputeNextOccurrenceRejectsGarbage(): void {
		$this->expectException(InvalidInputException::class);
		$this->service->computeNextOccurrence('NOT AN RRULE', self::NOW, self::NOW);
	}

	// ---- create -----------------------------------------------------------

	public function testCreateRequiresManageValidatesAndComputesNext(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (RecurRule $r): RecurRule {
				self::assertSame(1, $r->getBoardId());
				self::assertSame(10, $r->getTemplateCardId());
				self::assertSame(5, $r->getTargetStackId());
				self::assertSame(RecurRule::MODE_CLONE, $r->getMode());
				self::assertSame('alice', $r->getOwner());
				self::assertTrue($r->getEnabled());
				self::assertSame(0, $r->getOccurrencesSpawned());
				// next_occurrence_at is the first daily fire at/after now.
				self::assertSame(self::NOW, $r->getNextOccurrenceAt());
				$r->setId(7);
				return $r;
			});

		$rule = $this->service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'alice');
		self::assertSame(7, $rule->getId());
	}

	public function testCreateWithoutManageThrows403(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(NotPermittedException::class);
		$this->service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', 0, 0, false, 'bob');
	}

	public function testCreateRejectsGarbageRruleWith400(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 10, 5, RecurRule::MODE_CLONE, 'TOTAL GARBAGE', 0, 0, false, 'alice');
	}

	public function testCreateRejectsTemplateCardOnAnotherBoard(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// Template card lives on board 2.
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard(10, 2));
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', 0, 0, false, 'alice');
	}

	public function testCreateRejectsTargetStackOnAnotherBoard(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		// Stack lives on board 2.
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack(5, 2));
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', 0, 0, false, 'alice');
	}

	public function testCreateRejectsInvalidMode(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 10, 5, 99, 'FREQ=DAILY', 0, 0, false, 'alice');
	}

	// ---- listForBoard -----------------------------------------------------

	public function testListForBoardRequiresRead(): void {
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_READ);
		$this->ruleMapper->expects(self::once())->method('findByBoard')->with(1)->willReturn([$this->rule()]);

		self::assertCount(1, $this->service->listForBoard(1, 'alice'));
	}

	public function testListForBoardWithoutReadThrows403(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->ruleMapper->expects(self::never())->method('findByBoard');

		$this->expectException(NotPermittedException::class);
		$this->service->listForBoard(1, 'bob');
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

	// ---- spawn CLONE ------------------------------------------------------

	public function testSpawnCloneCreatesCardCopiesDescriptionLabelsAssignees(): void {
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, policy: RecurRule::POLICY_AT_OCCURRENCE, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());

		$created = $this->spawnedCard(99);
		$this->cardService->expects(self::once())
			->method('create')
			->with(5, 'Water the plants', 'alice')
			->willReturn($created);

		// Description + duedate applied via a card UPDATE (rides the CREATE).
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertSame('- [ ] living room', $c->getDescription());
				self::assertNotNull($c->getDuedate());
				// POLICY_AT_OCCURRENCE → due at the occurrence timestamp.
				self::assertSame(self::NOW, $c->getDuedate()->getTimestamp());
				return $c;
			});

		$this->cardLabelMapper->method('findLabelIdsByCard')->with(10)->willReturn([78, 79]);
		$this->cardLabelMapper->method('exists')->willReturn(false);
		$this->cardLabelMapper->expects(self::exactly(2))->method('insertAssignment');

		$this->cardAssigneeMapper->method('findUserIdsByCard')->with(10)->willReturn(['bob']);
		$this->cardAssigneeMapper->method('exists')->willReturn(false);
		$this->cardAssigneeMapper->expects(self::once())->method('insertAssignment')->with(99, 'bob');

		// The enrichment (description/labels/assignees) is logged as an UPDATE so
		// delta-sync reflects the full card, deferred push (transaction owns it).
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 99, Change::ACTION_UPDATE, 'alice', false, Change::VERB_UPDATED);
		$this->changeNotifier->expects(self::once())->method('emitPush')->with(1);

		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule);
		self::assertSame(99, $card->getId());
		self::assertSame(1, $rule->getOccurrencesSpawned());
		self::assertSame(99, $rule->getLastSpawnedAt());
	}

	public function testSpawnCloneOffsetPolicyAddsOffsetToDuedate(): void {
		$rule = $this->rule(policy: RecurRule::POLICY_OFFSET_AFTER, offset: 3600, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertSame(self::NOW + 3600, $c->getDuedate()->getTimestamp());
				return $c;
			});

		$this->service->spawn($rule);
	}

	public function testSpawnCloneNonePolicyLeavesDuedateNull(): void {
		$rule = $this->rule(policy: RecurRule::POLICY_NONE, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertNull($c->getDuedate());
				return $c;
			});

		$this->service->spawn($rule);
	}

	// ---- spawn RESET ------------------------------------------------------

	public function testSpawnResetMovesTemplateBackClearsDoneRearmsDuedate(): void {
		$rule = $this->rule(mode: RecurRule::MODE_RESET, policy: RecurRule::POLICY_AT_OCCURRENCE, nextOccurrenceAt: self::NOW);

		// Target stack empty → move to top (afterCardId null).
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);

		$moved = $this->templateCard();
		$moved->setStackId(5);
		$moved->setDoneAt(self::NOW); // was done before the reset
		$this->cardService->expects(self::once())
			->method('move')
			->with(10, 5, null, 'alice')
			->willReturn($moved);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertSame(0, $c->getDoneAt());
				self::assertFalse($c->getArchived());
				self::assertSame(self::NOW, $c->getDuedate()->getTimestamp());
				return $c;
			});

		// The done/duedate reset bypasses CardService, so it needs its own UPDATE
		// change row for delta-sync (only the MOVE reaches the log otherwise).
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 10, Change::ACTION_UPDATE, 'alice', false, Change::VERB_UPDATED);
		$this->changeNotifier->expects(self::once())->method('emitPush')->with(1);

		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule);
		self::assertSame(10, $card->getId());
		self::assertSame(1, $rule->getOccurrencesSpawned());
	}

	// ---- skip_while_open --------------------------------------------------

	public function testSpawnCloneSkipsWhenPreviousCardStillOpen(): void {
		$rule = $this->rule(skipWhileOpen: true, nextOccurrenceAt: self::NOW, lastSpawnedAt: 42, occurrencesSpawned: 1);

		$openCard = $this->spawnedCard(42); // done_at 0, not archived/deleted → open
		$this->cardMapper->method('find')->with(42)->willReturn($openCard);

		$this->cardService->expects(self::never())->method('create');
		// The schedule still advances (rule saved) so it does not re-fire now.
		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule);
		self::assertNull($card);
		// A skip is not an occurrence.
		self::assertSame(1, $rule->getOccurrencesSpawned());
	}

	public function testSpawnCloneDoesNotSkipWhenPreviousCardDone(): void {
		$rule = $this->rule(skipWhileOpen: true, nextOccurrenceAt: self::NOW, lastSpawnedAt: 42, occurrencesSpawned: 1);

		$doneCard = $this->spawnedCard(42);
		$doneCard->setDoneAt(self::NOW - 10); // closed
		$this->cardMapper->method('find')->willReturnCallback(function (int $id): Card {
			return $id === 42 ? (function () {
				$c = $this->spawnedCard(42);
				$c->setDoneAt(self::NOW - 10);
				return $c;
			})() : $this->templateCard();
		});
		$this->cardService->expects(self::once())->method('create')->willReturn($this->spawnedCard(100));
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule);
		self::assertNotNull($card);
		self::assertSame(2, $rule->getOccurrencesSpawned());
	}

	public function testManualSpawnIgnoresSkipWhileOpen(): void {
		$rule = $this->rule(skipWhileOpen: true, nextOccurrenceAt: self::NOW, lastSpawnedAt: 42);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->expects(self::once())->method('create')->willReturn($this->spawnedCard());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule, true);
		self::assertNotNull($card);
	}

	// ---- COUNT exhaustion self-disables -----------------------------------

	public function testSpawnCountExhaustionSelfDisables(): void {
		// A COUNT=1 rule: after this single spawn, no next occurrence → disable.
		$rule = $this->rule(rrule: 'FREQ=DAILY;COUNT=1', nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$this->service->spawn($rule);
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}

	// ---- atomic spawn (idempotency on cron retry) -------------------------

	public function testSpawnCommitsOnSuccess(): void {
		$rule = $this->rule(nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$this->service->spawn($rule);
	}

	/**
	 * The core idempotency guarantee: if an enrich write throws AFTER the card
	 * is created but BEFORE next_occurrence_at is advanced, the whole occurrence
	 * rolls back - the rule is never persisted with a bumped cursor and, because
	 * the card insert rolls back too, the next cron run does NOT leave a second
	 * card behind. Before the transaction wrap, the created card survived while
	 * the rule stayed due, so the next run stamped a duplicate.
	 */
	public function testSpawnRollsBackAndDoesNotAdvanceRuleWhenEnrichFails(): void {
		$rule = $this->rule(nextOccurrenceAt: self::NOW, occurrencesSpawned: 0);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		// Card gets created...
		$this->cardService->expects(self::once())->method('create')->willReturn($this->spawnedCard());
		// ...but the enrich UPDATE throws, mid-occurrence (simulating a crash /
		// failing write between the insert and the rule advance).
		$this->cardMapper->method('update')
			->willThrowException(new \RuntimeException('write died mid-spawn'));

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::never())->method('commit');
		// Everything in the occurrence is rolled back together.
		$this->db->expects(self::once())->method('rollBack');
		// The rule's schedule is NEVER advanced/persisted, so it stays due and
		// the retry is a clean re-spawn (not a duplicate on top of a ghost card).
		$this->ruleMapper->expects(self::never())->method('update');

		try {
			$this->service->spawn($rule);
			self::fail('spawn should propagate the failing write');
		} catch (\RuntimeException $e) {
			self::assertSame('write died mid-spawn', $e->getMessage());
		}

		// Bookkeeping was not committed to the in-memory entity past the failure
		// point: next_occurrence_at is untouched, so findDueEnabled still returns
		// it on the next run.
		self::assertSame(self::NOW, $rule->getNextOccurrenceAt());
		self::assertSame(0, $rule->getOccurrencesSpawned());
	}

	/**
	 * Delta-sync gap fix (#3575): the enrichment change row must land INSIDE the
	 * spawn transaction (before commit) so a ?since=<before> delta consuming the
	 * spawn sees the full card - description/labels/assignees, not just the title.
	 * The realtime push is deferred until AFTER commit (a pre-commit push could
	 * make a client refetch state the transaction may still roll back).
	 */
	public function testSpawnCloneLogsEnrichmentInsideTransactionAndPushesAfterCommit(): void {
		$rule = $this->rule(nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$order = [];
		$this->db->method('beginTransaction')->willReturnCallback(function () use (&$order): void {
			$order[] = 'begin';
		});
		$this->changeNotifier->method('notify')->willReturnCallback(
			function () use (&$order): Change {
				$order[] = 'notify';
				return new Change();
			}
		);
		$this->db->method('commit')->willReturnCallback(function () use (&$order): void {
			$order[] = 'commit';
		});
		$this->changeNotifier->method('emitPush')->willReturnCallback(function () use (&$order): void {
			$order[] = 'push';
		});

		$this->service->spawn($rule);

		// The change row is appended before commit; the push fires only after.
		self::assertSame(['begin', 'notify', 'commit', 'push'], $order);
	}

	public function testSpawnSkipCommitsAdvancedSchedule(): void {
		// A skip still opens a transaction, advances the schedule and commits -
		// so the rule does not re-fire immediately, atomically.
		$rule = $this->rule(skipWhileOpen: true, nextOccurrenceAt: self::NOW, lastSpawnedAt: 42, occurrencesSpawned: 1);
		$openCard = $this->spawnedCard(42);
		$this->cardMapper->method('find')->with(42)->willReturn($openCard);
		$this->cardService->expects(self::never())->method('create');
		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		self::assertNull($this->service->spawn($rule));
	}

	// ---- createNow --------------------------------------------------------

	public function testCreateNowRequiresManageAndSpawns(): void {
		$rule = $this->rule(nextOccurrenceAt: self::NOW);
		$board = $this->board();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->permissionService->expects(self::once())
			->method('assertPermission')
			->with($board, 'alice', PermissionService::PERMISSION_MANAGE);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->expects(self::once())->method('create')->willReturn($this->spawnedCard());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$card = $this->service->createNow(3, 'alice');
		self::assertSame(99, $card->getId());
	}

	public function testCreateNowWithoutManageThrows403(): void {
		$rule = $this->rule();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->permissionService->method('assertPermission')
			->willThrowException(new NotPermittedException());
		$this->cardService->expects(self::never())->method('create');

		$this->expectException(NotPermittedException::class);
		$this->service->createNow(3, 'bob');
	}

	// ---- runDueRules (cron entry) -----------------------------------------

	public function testRunDueRulesSpawnsEachAndSkipsBroken(): void {
		$ruleA = $this->rule(id: 1, nextOccurrenceAt: self::NOW);
		$ruleB = $this->rule(id: 2, nextOccurrenceAt: self::NOW);
		$this->ruleMapper->method('findDueEnabled')->with(self::NOW)->willReturn([$ruleA, $ruleB]);

		// Rule A's template card is gone → spawn throws; rule B still runs.
		$this->cardMapper->method('find')->willReturnCallback(function (int $id): Card {
			static $call = 0;
			$call++;
			if ($call === 1) {
				throw new DoesNotExistException('template gone');
			}
			return $this->templateCard();
		});
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);
		$this->logger->expects(self::once())->method('warning');

		self::assertSame(1, $this->service->runDueRules());
	}
}

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
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\NotPermittedException;
use OCA\Kanso\Service\PermissionService;
use OCA\Kanso\Service\RecurrenceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
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
	private CardVisibilityGuard&MockObject $visibilityGuard;
	private ITimeFactory&MockObject $time;
	private IDBConnection&MockObject $db;
	private IConfig&MockObject $config;
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
		// Every template is visible by default (assertVisible no-ops); the
		// #3760 hidden-template tests wire a throwing guard explicitly.
		$this->visibilityGuard = $this->createMock(CardVisibilityGuard::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->db = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		// Default: no personal timezone set → rules default to the server tz.
		$this->config->method('getUserValue')->willReturn('');
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
			$this->visibilityGuard,
			$this->time,
			$this->db,
			$this->config,
			$this->logger,
		);
	}

	/**
	 * A service whose visibility guard throws for the template - the #3760
	 * hidden-template cases (visibility narrowed past the viewer/owner).
	 */
	private function serviceWithHiddenTemplate(): RecurrenceService {
		$guard = $this->createMock(CardVisibilityGuard::class);
		$guard->method('assertVisible')
			->willThrowException(new DoesNotExistException('Card 10 does not exist'));
		return new RecurrenceService(
			$this->ruleMapper,
			$this->cardMapper,
			$this->stackMapper,
			$this->boardMapper,
			$this->cardLabelMapper,
			$this->cardAssigneeMapper,
			$this->cardService,
			$this->changeNotifier,
			$this->permissionService,
			$guard,
			$this->time,
			$this->db,
			$this->config,
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
		// Anchor in UTC so the +86400 daily-step assertions are host-tz agnostic.
		$rule->setTimezone('UTC');
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
				// A daily rule created "now" first fires tomorrow, not now. We skip
				// the date that lands on the creation moment so a brand-new rule is
				// never ready to fire immediately (#80).
				self::assertSame(self::NOW + 86400, $r->getNextOccurrenceAt());
				$r->setId(7);
				return $r;
			});

		$rule = $this->service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'alice');
		self::assertSame(7, $rule->getId());
	}

	public function testCreateIsNotImmediatelyDue(): void {
		// Regression for #80. A card set to repeat "Yearly" should sit quietly for a
		// year. The bug was that a brand-new rule was ready to fire straight away, so
		// the next cron run (within ~15 min) reset the card and overwrote the date
		// the user had just picked. Its first fire must be in the future, not now.
		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (RecurRule $r): RecurRule {
				// In the future, so the cron (which only picks up rules whose next
				// fire is at or before now) leaves it alone until next year.
				self::assertGreaterThan(self::NOW, $r->getNextOccurrenceAt());
				$r->setId(7);
				return $r;
			});

		$this->service->create(1, 10, 5, RecurRule::MODE_RESET, 'FREQ=YEARLY', RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'alice');
	}

	public function testCreateAnchorsOnTheCardStartDateAndFiresOnAFutureStart(): void {
		// The schedule is anchored at the card's Start date, so a Start set 30 days
		// out makes the FIRST occurrence land on that date (not now, not skipped) -
		// "starts <future date>, repeats yearly" fires on the start date.
		$futureStart = self::NOW + 30 * 86400;
		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $futureStart));

		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (RecurRule $r) use ($futureStart): RecurRule {
				self::assertSame($futureStart, $r->getNextOccurrenceAt());
				$r->setId(7);
				return $r;
			});

		$this->service->create(1, 10, 5, RecurRule::MODE_RESET, 'FREQ=YEARLY', RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'alice');
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
		// The rule's template card (id 10) is live, so the rule is listed.
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard(10));

		self::assertCount(1, $this->service->listForBoard(1, 'alice'));
	}

	public function testListForBoardHidesRulesWhoseTemplateIsTrashed(): void {
		// #67: a rule whose template card is soft-deleted is paused and kept for
		// restore, but must NOT show in the automation list (looks like an orphan).
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$live = $this->rule(3);
		$live->setTemplateCardId(10);
		$trashed = $this->rule(4);
		$trashed->setTemplateCardId(11);
		$this->ruleMapper->method('findByBoard')->with(1)->willReturn([$live, $trashed]);

		$trashedCard = $this->templateCard(11);
		$trashedCard->setDeletedAt(self::NOW); // in the trash
		$this->cardMapper->method('find')->willReturnMap([
			[10, $this->templateCard(10)],
			[11, $trashedCard],
		]);

		$rules = $this->service->listForBoard(1, 'alice');
		self::assertCount(1, $rules);
		self::assertSame(3, $rules[0]->getId(), 'only the live-template rule survives');
	}

	public function testListForBoardHidesRulesWhoseTemplateWasPurged(): void {
		// Template hard-deleted (find throws) → stale orphan rule; hide it too.
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->ruleMapper->method('findByBoard')->with(1)->willReturn([$this->rule()]);
		$this->cardMapper->method('find')->with(10)
			->willThrowException(new DoesNotExistException('purged'));

		self::assertSame([], $this->service->listForBoard(1, 'alice'));
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
				// The template has no dates, so a repeat invents none: the clone
				// carries no Start/End date (the window model, replacing the old
				// "stamp a due date at the occurrence" behaviour).
				self::assertNull($c->getStartDate());
				self::assertNull($c->getDuedate());
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
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);

		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule);
		self::assertSame(99, $card->getId());
		self::assertSame(1, $rule->getOccurrencesSpawned());
		self::assertSame(99, $rule->getLastSpawnedAt());
	}

	public function testSpawnCloneSlidesStartEndWindowToOccurrence(): void {
		// The template has a 2-day Start→End window anchored in the past. A clone's
		// window slides forward to the occurrence, keeping its 2-day length (the
		// calendar-event model): Start = occurrence, End = occurrence + 2 days.
		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . (self::NOW - 10 * 86400)));
		$template->setDuedate(new \DateTime('@' . (self::NOW - 10 * 86400 + 2 * 86400)));
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertSame(self::NOW, $c->getStartDate()->getTimestamp());
				self::assertSame(self::NOW + 2 * 86400, $c->getDuedate()->getTimestamp());
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

	public function testSpawnCloneCopiesTemplateAllDayFlag(): void {
		// #4125: an all-day template must spawn an all-day clone, else the clone
		// defaults to all_day=false and shows a spurious 00:00 time.
		$template = $this->templateCard();
		$template->setAllDay(true);
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, policy: RecurRule::POLICY_AT_OCCURRENCE, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardService->method('create')->willReturn($this->spawnedCard(99));
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertTrue($c->getAllDay());
				return $c;
			});

		$this->service->spawn($rule);
	}

	public function testSpawnCloneDefaultsAllDayFalseForNonAllDayTemplate(): void {
		// A template with no all-day flag (null) spawns a timed clone (all_day=false),
		// never null - the flag is always set explicitly on the clone.
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, policy: RecurRule::POLICY_AT_OCCURRENCE, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->method('create')->willReturn($this->spawnedCard(99));
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertFalse($c->getAllDay());
				return $c;
			});

		$this->service->spawn($rule);
	}

	// ---- soft-trashed template pauses the schedule (#4124) ----------------

	public function testSpawnPausesWhenTemplateIsSoftTrashed(): void {
		// A soft-trashed template (deleted_at > 0, not purged) must NOT spawn: the
		// spawn hot path read the template raw and kept cloning it. It pauses -
		// no card, no error, no hard-disable - and advances the schedule so the
		// cron does not busy-loop; the rule stays enabled to resume on restore.
		$trashed = $this->templateCard();
		$trashed->setDeletedAt(self::NOW - 5);
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($trashed);

		// Nothing is created and no enrichment fires.
		$this->cardService->expects(self::never())->method('create');
		$this->cardService->expects(self::never())->method('move');
		$this->changeNotifier->expects(self::never())->method('notify');
		// The schedule still advances (rule saved) so the cron does not re-fire now.
		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule);

		self::assertNull($card);
		// A pause is not an occurrence: counters untouched...
		self::assertSame(0, $rule->getOccurrencesSpawned());
		// ...but the cursor advanced past this occurrence to tomorrow...
		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
		// ...and the rule stays enabled so it resumes when the template is restored.
		self::assertTrue($rule->getEnabled());
	}

	public function testSpawnPausesWhenResetTemplateIsSoftTrashed(): void {
		// RESET mode moves the template card itself - a soft-trashed template must
		// pause too, never move a trashed card back into play.
		$trashed = $this->templateCard();
		$trashed->setDeletedAt(self::NOW - 5);
		$rule = $this->rule(mode: RecurRule::MODE_RESET, rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($trashed);

		$this->cardService->expects(self::never())->method('move');
		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		self::assertNull($this->service->spawn($rule));
		self::assertTrue($rule->getEnabled());
	}

	public function testSpawnResumesAfterTemplateRestored(): void {
		// Once the template is restored (deleted_at back to 0) the very next spawn
		// clones again - the pause left the rule enabled and did not disable it.
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW);
		// A live (restored) template.
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->expects(self::once())->method('create')->willReturn($this->spawnedCard(99));
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule);

		self::assertNotNull($card);
		self::assertSame(1, $rule->getOccurrencesSpawned());
	}

	// ---- spawn RESET ------------------------------------------------------

	public function testSpawnResetMovesTemplateBackClearsDoneSlidesWindow(): void {
		$rule = $this->rule(mode: RecurRule::MODE_RESET, nextOccurrenceAt: self::NOW);

		// Target stack empty → move to top (afterCardId null).
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);

		$moved = $this->templateCard();
		$moved->setStackId(5);
		$moved->setDoneAt(self::NOW); // was done before the reset
		// The card carries a 1-hour Start→End window anchored a week back; the reset
		// slides that whole window forward to the occurrence, keeping its length.
		$moved->setStartDate(new \DateTime('@' . (self::NOW - 7 * 86400)));
		$moved->setDuedate(new \DateTime('@' . (self::NOW - 7 * 86400 + 3600)));
		$this->cardService->expects(self::once())
			->method('move')
			->with(10, 5, null, 'alice')
			->willReturn($moved);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertSame(0, $c->getDoneAt());
				self::assertFalse($c->getArchived());
				// Window slid forward to the occurrence, 1-hour length preserved.
				self::assertSame(self::NOW, $c->getStartDate()->getTimestamp());
				self::assertSame(self::NOW + 3600, $c->getDuedate()->getTimestamp());
				return $c;
			});

		// The done/duedate reset bypasses CardService, so it needs its own UPDATE
		// change row for delta-sync (only the MOVE reaches the log otherwise).
		$this->changeNotifier->expects(self::once())
			->method('notify')
			->with(1, Change::ENTITY_CARD, 10, Change::ACTION_UPDATE, 'alice', false, Change::VERB_UPDATED);
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);

		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$card = $this->service->spawn($rule);
		self::assertSame(10, $card->getId());
		self::assertSame(1, $rule->getOccurrencesSpawned());
	}

	// ---- skip_while_open --------------------------------------------------

	public function testSpawnCloneSkipsWhenPreviousCardStillOpen(): void {
		$rule = $this->rule(skipWhileOpen: true, nextOccurrenceAt: self::NOW, lastSpawnedAt: 42, occurrencesSpawned: 1);

		// spawn() reads the (live) template first, then previousCardOpen reads the
		// last spawned card (42), still open → skip.
		$openCard = $this->spawnedCard(42); // done_at 0, not archived/deleted → open
		$this->cardMapper->method('find')->willReturnCallback(function (int $id) use ($openCard): Card {
			return $id === 42 ? $openCard : $this->templateCard();
		});

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
		$this->changeNotifier->method('pushBoardChanged')->willReturnCallback(function () use (&$order): void {
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
		// spawn() reads the (live) template first, then the last spawned card (42).
		$this->cardMapper->method('find')->willReturnCallback(function (int $id) use ($openCard): Card {
			return $id === 42 ? $openCard : $this->templateCard();
		});
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

	public function testCreateNowRefusesAHiddenTemplateLikeAMissingCard(): void {
		// create-now RETURNS the spawned card (title, description) to the
		// actor - a template hidden from them must read as missing (#3760),
		// or the endpoint would be a read oracle for hidden content.
		$rule = $this->rule(nextOccurrenceAt: self::NOW);
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->expects(self::never())->method('create');

		$this->expectException(DoesNotExistException::class);
		$this->serviceWithHiddenTemplate()->createNow(3, 'mgr');
	}

	// ---- runDueRules (cron entry) -----------------------------------------

	public function testRunDueRulesSpawnsEachAndSkipsBroken(): void {
		$ruleA = $this->rule(id: 1, nextOccurrenceAt: self::NOW);
		// Rule A's template card (id 11) is hard-gone; rule B's (id 10) is live.
		$ruleA->setTemplateCardId(11);
		$ruleB = $this->rule(id: 2, nextOccurrenceAt: self::NOW);
		$this->ruleMapper->method('findDueEnabled')->with(self::NOW)->willReturn([$ruleA, $ruleB]);

		// Rule A's template card is gone → spawn throws; rule B still runs. Keyed on
		// the template id (not a call counter) so it survives spawn() reading the
		// template once up front (#4124) instead of only inside spawnClone.
		$this->cardMapper->method('find')->willReturnCallback(function (int $id): Card {
			if ($id === 11) {
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

	// ---- catch-up on missed occurrences (#3587) ---------------------------

	/**
	 * A rule whose next_occurrence_at is N intervals in the past spawns N cards
	 * in a single cron run - one per missed occurrence, not one per run. The
	 * cursor walks occurrence-by-occurrence; runDueRules keeps calling spawn()
	 * while the rule stays due (next_occurrence_at <= now).
	 */
	public function testCatchUpSpawnsOneCardPerMissedOccurrence(): void {
		// Daily rule; cursor sits 3 days before now → 3 missed daily occurrences
		// are due (t-3d, t-2d, t-1d); the t=now one is also due (<= now) = 4.
		$firstMissed = self::NOW - 3 * 86400;
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: $firstMissed);
		// The template's Start date anchors the DAILY series (anchorFor); set it to
		// the first missed point so occurrences land on exact 24h steps from there,
		// and each spawned card's Start date slides to its own occurrence.
		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $firstMissed));

		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		// A fresh card per occurrence, and capture the per-occurrence Start dates.
		$starts = [];
		$id = 100;
		$this->cardService->method('create')->willReturnCallback(function () use (&$id): Card {
			return $this->spawnedCard($id++);
		});
		$this->cardMapper->method('update')->willReturnCallback(static function (Card $c) use (&$starts): Card {
			$starts[] = $c->getStartDate()?->getTimestamp();
			return $c;
		});

		$spawned = $this->service->runDueRules();

		// t-3d, t-2d, t-1d, t=now → 4 cards, each sliding its Start date to its own occurrence.
		self::assertSame(4, $spawned);
		self::assertSame([
			self::NOW - 3 * 86400,
			self::NOW - 2 * 86400,
			self::NOW - 1 * 86400,
			self::NOW,
		], $starts);
		// Cursor advanced past now to tomorrow; rule is no longer due.
		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
	}

	/**
	 * Catch-up is BOUNDED: a rule dormant far longer than the cap spawns at most
	 * MAX_CATCHUP cards in one run, logs the truncation, and stays due so the
	 * remainder continue on the next run.
	 */
	public function testCatchUpIsBoundedByMaxCatchupAndLogsTruncation(): void {
		// Hourly rule dormant for far more than MAX_CATCHUP hours.
		$backlog = RecurrenceService::MAX_CATCHUP + 20;
		$first = self::NOW - $backlog * 3600;
		$rule = $this->rule(rrule: 'FREQ=HOURLY', nextOccurrenceAt: $first);
		$rule->setCreatedAt($first);

		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);
		$id = 1000;
		$this->cardService->method('create')->willReturnCallback(function () use (&$id): Card {
			return $this->spawnedCard($id++);
		});
		$this->cardMapper->method('update')->willReturnArgument(0);

		// Truncation is logged once.
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('catch-up truncated'));

		$spawned = $this->service->runDueRules();

		self::assertSame(RecurrenceService::MAX_CATCHUP, $spawned);
		// Rule is still due (cursor advanced by exactly MAX_CATCHUP hours, still
		// in the past), so the remainder continue next run.
		self::assertSame($first + RecurrenceService::MAX_CATCHUP * 3600, $rule->getNextOccurrenceAt());
		self::assertLessThanOrEqual(self::NOW, $rule->getNextOccurrenceAt());
		self::assertTrue($rule->getEnabled());
	}

	/**
	 * Durable partial progress across a simulated mid-run interruption: with two
	 * missed occurrences due, if the SECOND occurrence's write throws, the first
	 * one stays committed (its own transaction) and only the second rolls back.
	 * The rule's cursor is left at the second occurrence, so the retry re-spawns
	 * ONLY the second - never a duplicate of the already-committed first.
	 */
	public function testCatchUpMakesDurablePartialProgressNoDoubleSpawn(): void {
		$firstMissed = self::NOW - 2 * 86400;
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: $firstMissed);
		$rule->setCreatedAt($firstMissed);

		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);
		$this->cardService->method('create')->willReturn($this->spawnedCard(200));

		// First occurrence enriches fine; the second occurrence's enrich write
		// dies mid-transaction (simulated crash).
		$call = 0;
		$this->cardMapper->method('update')->willReturnCallback(static function (Card $c) use (&$call): Card {
			$call++;
			if ($call === 2) {
				throw new \RuntimeException('write died on the 2nd occurrence');
			}
			return $c;
		});

		// The failing occurrence rolls back; runDueRules swallows and logs it.
		$this->db->method('rollBack');
		$this->logger->expects(self::atLeastOnce())->method('warning');

		$this->service->runDueRules();

		// The FIRST occurrence committed and advanced the cursor to the second
		// (t-1d); the second rolled back and did NOT advance further. A retry
		// therefore starts at the second occurrence - no double-spawn of the first.
		self::assertSame(self::NOW - 86400, $rule->getNextOccurrenceAt());
		self::assertSame(1, $rule->getOccurrencesSpawned());
	}

	// ---- timezone / DST stability (#3587) ---------------------------------

	/**
	 * A daily rule anchored in a DST-observing zone fires at a STABLE local hour
	 * on both sides of a spring-forward transition: the UTC instant shifts by an
	 * hour but "09:00 local" stays 09:00 local. We must not hand-roll DST -
	 * sabre's RRuleIterator does it when anchored in a real zone.
	 */
	public function testDailyRuleKeepsStableLocalHourAcrossDstBoundary(): void {
		// Europe/Berlin springs forward 2026-03-29 (02:00 → 03:00 CET→CEST).
		// Anchor a daily 09:00 rule the day before the transition.
		$tz = new \DateTimeZone('Europe/Berlin');
		$anchor = (new \DateTimeImmutable('2026-03-28 09:00:00', $tz))->getTimestamp();

		// Next occurrence after the anchor day = 2026-03-29 09:00 local, which is
		// AFTER the DST jump, so its UTC instant is one hour earlier than a naive
		// +86400 would give.
		$next = $this->service->computeNextOccurrence('FREQ=DAILY', $anchor, $anchor, 'Europe/Berlin');

		$expected = (new \DateTimeImmutable('2026-03-29 09:00:00', $tz))->getTimestamp();
		self::assertSame($expected, $next);

		// The local wall-clock hour is stable (09), even though the UTC offset
		// changed from +01:00 to +02:00 across the boundary.
		$before = (new \DateTimeImmutable('@' . $anchor))->setTimezone($tz);
		$after = (new \DateTimeImmutable('@' . $next))->setTimezone($tz);
		self::assertSame('09', $before->format('H'));
		self::assertSame('09', $after->format('H'));
		// UTC instants are 23h apart (spring forward), not 24h - proof the zone,
		// not a fixed 86400, drove the step.
		self::assertSame(23 * 3600, $next - $anchor);
	}

	/**
	 * A rule with a NULL timezone (created before #3587) falls back to the server
	 * default timezone (pinned to UTC in the test bootstrap) - it still expands,
	 * it does not throw.
	 */
	public function testNullTimezoneFallsBackToServerDefault(): void {
		$anchor = self::NOW;
		$next = $this->service->computeNextOccurrence('FREQ=DAILY', $anchor, $anchor, null);
		// UTC has no DST, so a plain +86400 step.
		self::assertSame($anchor + 86400, $next);
	}

	/**
	 * On create, the rule's timezone defaults to the owner's Nextcloud personal
	 * timezone (core/timezone user value).
	 */
	public function testCreateDefaultsTimezoneToOwnerPersonalTimezone(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->with('alice', 'core', 'timezone', '')
			->willReturn('America/New_York');
		$service = new RecurrenceService(
			$this->ruleMapper, $this->cardMapper, $this->stackMapper, $this->boardMapper,
			$this->cardLabelMapper, $this->cardAssigneeMapper, $this->cardService,
			$this->changeNotifier, $this->permissionService, $this->visibilityGuard,
			$this->time, $this->db, $config, $this->logger,
		);

		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->method('insert')->willReturnCallback(static function (RecurRule $r): RecurRule {
			self::assertSame('America/New_York', $r->getTimezone());
			$r->setId(9);
			return $r;
		});

		$service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'alice');
	}

	// ---- visibility (#3760) ------------------------------------------------

	public function testSpawnedCloneInheritsTemplateVisibilityAndCreatorRoleVerbatim(): void {
		// A provider-internal template spawns a provider-internal card - the
		// class and the frozen creator side ride the CREATE itself, so no
		// fan-out ever sees a wider interim 'public' card.
		$template = $this->templateCard();
		$template->setVisibility(CardVisibilityScope::VISIBILITY_INTERNAL);
		$template->setCreatorRole('internal');
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($template);

		$this->cardService->expects(self::once())
			->method('create')
			->with(5, 'Water the plants', 'alice', null, null, CardVisibilityScope::VISIBILITY_INTERNAL, 'internal')
			->willReturn($this->spawnedCard(99));
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		self::assertNotNull($this->service->spawn($rule));
	}

	public function testLegacyTemplateWithoutVisibilitySpawnsPublic(): void {
		// Pre-migration template rows (NULL visibility) read as 'public' - the
		// clone gets the explicit backfill value, creator side left to create().
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());

		$this->cardService->expects(self::once())
			->method('create')
			->with(5, 'Water the plants', 'alice', null, null, CardVisibilityScope::VISIBILITY_PUBLIC, null)
			->willReturn($this->spawnedCard(99));
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		self::assertNotNull($this->service->spawn($rule));
	}

	public function testSpawnRefusesWhenTemplateIsHiddenFromTheRuleOwner(): void {
		// The template's visibility narrowed past the rule owner after the rule
		// was created: cloning its content into a card the owner CAN see would
		// be a leak - the spawn fails like a missing template, nothing created.
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->expects(self::never())->method('create');
		$this->ruleMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->serviceWithHiddenTemplate()->spawn($rule);
	}

	public function testCreateRefusesAHiddenTemplateLikeAMissingCard(): void {
		// Anchoring a rule on a card the creator cannot SEE is a 404 - same as
		// a bogus id, no existence oracle.
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->serviceWithHiddenTemplate()->create(
			1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'mgr',
		);
	}

	public function testUpdateRefusesAHiddenTemplateLikeAMissingCard(): void {
		$rule = $this->rule();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);
		$this->serviceWithHiddenTemplate()->update(3, null, null, null, null, null, null, null, null, 'mgr');
	}

	// ---- update() must not rewind the cursor onto an already-fired occurrence (#65)

	/**
	 * Common wiring for an update() that reaches the setters: the rule, board,
	 * manage permission, visible template and target stack are all resolved.
	 */
	private function wireUpdate(RecurRule $rule): void {
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->method('update')->willReturnArgument(0);
	}

	/**
	 * The reported bug (#65): a daily rule that already fired TODAY has its cursor
	 * sitting on tomorrow. Re-writing the SAME RRULE (e.g. the card's Repeat
	 * control saving an unchanged rule) must NOT recompute the cursor - recomputing
	 * from now-1 would rewind it back onto today, an occurrence the cron already
	 * spawned, so the next cron run would stamp a duplicate clone dated today.
	 */
	public function testUpdateWithUnchangedRruleDoesNotRewindCursorOntoToday(): void {
		// Cursor already advanced past today's fire to tomorrow.
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 86400);
		$this->wireUpdate($rule);

		// Same RRULE, no schedule change → cursor must stay put.
		$this->service->update(3, null, null, null, 'FREQ=DAILY', null, null, null, null, 'alice');

		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
	}

	/**
	 * A no-op {enabled} toggle (the rule was already enabled, e.g. saving board
	 * settings) leaves the schedule untouched, so it must NOT re-arm the cursor
	 * either - same duplicate-clone risk as an unchanged-RRULE edit (#65).
	 */
	public function testUpdateWithNoOpEnableToggleDoesNotRewindCursor(): void {
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 86400);
		$this->wireUpdate($rule);

		// enabled=true but the rule was already enabled → not a re-enable.
		$this->service->update(3, null, null, null, null, null, null, null, true, 'alice');

		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
	}

	/**
	 * A genuine schedule change (a DIFFERENT RRULE) DOES re-arm the cursor to the
	 * next occurrence of the new schedule - the deliberate re-arm is preserved.
	 */
	public function testUpdateWithChangedRruleReArmsCursor(): void {
		// Daily rule, cursor on tomorrow; switch it to weekly.
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 86400);
		$this->wireUpdate($rule);

		$this->service->update(3, null, null, null, 'FREQ=WEEKLY', null, null, null, null, 'alice');

		// Anchored at createdAt (NOW), the next weekly occurrence after the current
		// cursor (tomorrow) is one week out - re-armed forward, not rewound.
		self::assertSame(self::NOW + 7 * 86400, $rule->getNextOccurrenceAt());
	}

	/**
	 * Speeding a rule up takes effect right away. If the user changes a rule from
	 * Weekly to Daily, it should start firing daily now - not wait for the old
	 * weekly date. The old code kept the far-off date, so "Daily" did nothing for
	 * up to a week (#80 follow-up).
	 */
	public function testUpdateToMoreFrequentCadenceTakesEffectFromNextOccurrence(): void {
		// A weekly rule whose next fire is two days away.
		$rule = $this->rule(rrule: 'FREQ=WEEKLY', nextOccurrenceAt: self::NOW + 2 * 86400);
		$this->wireUpdate($rule);

		// Switch it to hourly. The next hourly slot after now is one hour from now,
		// so it starts almost immediately instead of waiting two more days.
		$this->service->update(3, null, null, null, 'FREQ=HOURLY', null, null, null, null, 'alice');

		self::assertSame(self::NOW + 3600, $rule->getNextOccurrenceAt());
	}

	/**
	 * Changing a rule's schedule never leaves it ready to fire the instant the next
	 * cron runs - the new fire time is always in the future. This keeps schedule
	 * edits from behaving like the "reset to today" bug (#80 family).
	 */
	public function testUpdateWithChangedRruleIsNotImmediatelyDue(): void {
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 86400);
		$this->wireUpdate($rule);

		$this->service->update(3, null, null, null, 'FREQ=WEEKLY', null, null, null, null, 'alice');

		self::assertGreaterThan(self::NOW, $rule->getNextOccurrenceAt());
	}

	/**
	 * Turning a rule back on resumes it on its next future date - not stuck in the
	 * past, and not firing the instant the cron runs.
	 */
	public function testUpdateReEnablingDisabledRuleReArmsToNextFutureOccurrence(): void {
		// A switched-off rule whose stored next fire is stale (10 days ago).
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW - 10 * 86400);
		$rule->setEnabled(false);
		$this->wireUpdate($rule);

		$this->service->update(3, null, null, null, null, null, null, null, true, 'alice');

		// Back on, and pointed at tomorrow's daily slot - not the old past date and
		// not right now.
		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
		self::assertTrue($rule->getEnabled());
	}
}

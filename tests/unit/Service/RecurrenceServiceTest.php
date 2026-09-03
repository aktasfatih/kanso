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

	/**
	 * A rule with no FREQ is the nastiest shape of malformed: sabre never checks
	 * that FREQ is present, so the iterator CONSTRUCTS cleanly and the damage only
	 * shows while stepping. What it looks like there depends on the sabre release,
	 * which is why this rejection has to happen BEFORE an iterator exists:
	 *   - on vobject 5 the frequency property is typed, so stepping raises an
	 *     \Error a catch can turn into a 400;
	 *   - on vobject 4 - the line Nextcloud bundles, and therefore the one this app
	 *     runs against in production - it is untyped and simply null, so no switch
	 *     arm matches, the cursor never advances, and fastForward() loops forever.
	 *     There is no throw to catch: the request wedges its worker, and under the
	 *     cron CLI (no time limit) it wedges the background job.
	 * composer.lock pins the 4.x line precisely so this test exercises the version
	 * that ships.
	 *
	 * "" is the API's default for an omitted rrule parameter, so it is reachable by
	 * simply leaving the field out of the request.
	 *
	 * The anchoring is deliberate - see {@see self::assertRejectedWithoutStepping}.
	 *
	 * @testWith [""]
	 *           ["   "]
	 *           ["COUNT=3"]
	 *           ["INTERVAL=2"]
	 *           ["COUNT=3;INTERVAL=2"]
	 */
	public function testComputeNextOccurrenceRejectsAFreqLessRule(string $rrule): void {
		$this->assertRejectedWithoutStepping($rrule);
	}

	/**
	 * A FREQ that is present but not a frequency at all. Sabre's own constructor
	 * rejects these, but the guard must reject them FIRST and on its own terms -
	 * otherwise it is only a presence check, and a presence check is not enough
	 * (see the SECONDLY/MINUTELY tests below).
	 *
	 * @testWith ["FREQ="]
	 *           ["FREQ=BOGUS"]
	 *           ["FREQ=DAILYISH"]
	 */
	public function testComputeNextOccurrenceRejectsAPresentButInvalidFreq(string $rrule): void {
		$this->assertRejectedWithoutStepping($rrule);
	}

	/**
	 * SECONDLY and MINUTELY are the half of the hole a presence check leaves open.
	 * They are valid RFC 5545 and sabre's iterator CONSTRUCTOR accepts them - its
	 * frequency whitelist carries all seven values - but its next() implements only
	 * five. On these two it returns without touching the cursor, so fastForward()'s
	 * unbounded `while (valid() && current < target)` spins exactly as a FREQ-less
	 * rule does, with nothing thrown to catch. A guard that asked only "is FREQ
	 * present?" waved both straight through to that spin.
	 *
	 * {@see self::testSabreCannotStepSecondlyOrMinutely} is the companion proof that
	 * this is sabre's behaviour and not an assumption.
	 *
	 * The anchoring is deliberate - see {@see self::assertRejectedWithoutStepping}.
	 *
	 * @testWith ["FREQ=SECONDLY"]
	 *           ["FREQ=MINUTELY"]
	 *           ["FREQ=SECONDLY;INTERVAL=30"]
	 *           ["FREQ=MINUTELY;COUNT=5"]
	 *           ["freq=secondly"]
	 */
	public function testComputeNextOccurrenceRejectsAFrequencySabreCannotStep(string $rrule): void {
		$this->assertRejectedWithoutStepping($rrule);
	}

	/**
	 * Asserts computeNextOccurrence() refuses $rrule - and does it in a shape that
	 * FAILS rather than HANGS if the guard is ever weakened.
	 *
	 * Every rule these tests feed in is one sabre's iterator cannot step: the cursor
	 * stands still, so fastForward()'s `while (valid() && currentDate < $target)`
	 * would spin forever if it were ever entered. Asking for the occurrence after
	 * `$anchor - 1` makes the target the anchor itself, so `currentDate < $target` is
	 * false on the very first check and the loop body never runs. A regressed guard
	 * therefore lets the call RETURN (the anchor) instead of throwing, and the
	 * missing-exception failure is instant.
	 *
	 * Do not "simplify" this to (NOW, NOW). That asks for the occurrence strictly
	 * after the anchor, which enters the loop - and a regression then wedges the
	 * whole suite instead of reddening it. That is precisely what stalled a test run
	 * for an hour while this bug was being investigated.
	 */
	private function assertRejectedWithoutStepping(string $rrule): void {
		$anchor = self::NOW;
		$this->expectException(InvalidInputException::class);
		$this->service->computeNextOccurrence($rrule, $anchor - 1, $anchor);
	}

	/**
	 * The five frequencies sabre CAN step must still be accepted - the guard is an
	 * allowlist, and an allowlist that is too narrow breaks every real rule. Each is
	 * asserted to produce a real occurrence strictly after the anchor.
	 *
	 * @testWith ["FREQ=HOURLY", 3600]
	 *           ["FREQ=DAILY", 86400]
	 *           ["FREQ=WEEKLY", 604800]
	 *           ["FREQ=MONTHLY", 2678400]
	 *           ["FREQ=YEARLY", 31536000]
	 */
	public function testComputeNextOccurrenceAcceptsEveryIterableFrequency(string $rrule, int $expectedDelta): void {
		$anchor = (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp();
		$next = $this->service->computeNextOccurrence($rrule, $anchor, $anchor, 'UTC');
		self::assertSame($anchor + $expectedDelta, $next);
	}

	/**
	 * Pins down WHY SECONDLY and MINUTELY have to be rejected, straight against the
	 * sabre the suite runs (4.x, pinned in composer.json - the line Nextcloud
	 * bundles). It is deliberately BOUNDED: it steps the iterator a fixed number of
	 * times and asserts the cursor moved, rather than calling fastForward() on an
	 * unguarded rule. fastForward() on these never returns, so a test written that
	 * way would HANG the suite instead of failing it - which is exactly how this
	 * wedged a test run for an hour while it was being investigated. Never reach for
	 * fastForward() here.
	 *
	 * @testWith ["SECONDLY", false]
	 *           ["MINUTELY", false]
	 *           ["HOURLY", true]
	 *           ["DAILY", true]
	 *           ["WEEKLY", true]
	 *           ["MONTHLY", true]
	 *           ["YEARLY", true]
	 */
	public function testSabreCannotStepSecondlyOrMinutely(string $freq, bool $expectedToAdvance): void {
		$start = new \DateTimeImmutable('2026-01-01T00:00:00', new \DateTimeZone('UTC'));
		// The constructor accepts all seven - that is the trap.
		$iterator = new \Sabre\VObject\Recur\RRuleIterator('FREQ=' . $freq, $start);
		for ($i = 0; $i < 5; $i++) {
			$iterator->next();
		}
		$advanced = $iterator->current() === null
			|| $iterator->current()->getTimestamp() !== $start->getTimestamp();
		self::assertSame(
			$expectedToAdvance,
			$advanced,
			sprintf(
				'FREQ=%s: expected the cursor %s after 5 next() calls',
				$freq,
				$expectedToAdvance ? 'to advance' : 'to stand still',
			),
		);
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

	public function testFutureAnchorFiresOnTheAnchorThenFollowsTheByMonthDayRule(): void {
		// Documents the anchor contract the MCP/UI hints now spell out. The Start
		// date is a FUTURE date that is deliberately NOT the 15th: RFC 5545 puts
		// DTSTART itself in the recurrence set, so occurrence #1 is the Start date
		// to the minute, UNFILTERED by BYMONTHDAY. Only from #2 on does the BY*
		// part apply. Timezone pinned to UTC so the calendar-day maths is not
		// host-tz dependent.
		$anchor = (new \DateTimeImmutable('2027-03-04T09:00:00Z'))->getTimestamp();
		self::assertGreaterThan(self::NOW, $anchor, 'the anchor must be in the future for this case');

		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $anchor));

		$board = $this->board();
		$this->boardMapper->method('find')->with(1)->willReturn($board);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(static function (RecurRule $r): RecurRule {
				$r->setId(7);
				return $r;
			});

		$rule = $this->service->create(
			1, 10, 5,
			RecurRule::MODE_CLONE,
			'FREQ=MONTHLY;BYMONTHDAY=15',
			RecurRule::POLICY_AT_OCCURRENCE,
			0,
			false,
			'alice',
			'UTC',
		);

		// #1 - the anchor itself (4 March), NOT the 15th.
		$first = $rule->getNextOccurrenceAt();
		self::assertSame($anchor, $first);

		// #2 and #3 - now the BYMONTHDAY filter bites: the 15th of each month,
		// keeping the anchor's 09:00 wall-clock time.
		$second = $this->service->computeNextOccurrence('FREQ=MONTHLY;BYMONTHDAY=15', $first, $anchor, 'UTC');
		self::assertSame((new \DateTimeImmutable('2027-03-15T09:00:00Z'))->getTimestamp(), $second);

		$third = $this->service->computeNextOccurrence('FREQ=MONTHLY;BYMONTHDAY=15', $second, $anchor, 'UTC');
		self::assertSame((new \DateTimeImmutable('2027-04-15T09:00:00Z'))->getTimestamp(), $third);
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

	/**
	 * A FREQ-less rule is a 400 like any other bad input - not a fatal, and (on the
	 * sabre line that ships) not a hung request either. sabre builds the iterator
	 * for it without complaint, so validate() has to refuse the rule before one is
	 * ever constructed.
	 *
	 * "" is what the controller passes when the request simply OMITS rrule, which
	 * makes this the cheapest possible way in.
	 *
	 * @testWith [""]
	 *           ["   "]
	 *           ["COUNT=3"]
	 *           ["INTERVAL=2"]
	 */
	public function testCreateRejectsAFreqLessRruleWith400(string $rrule): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 10, 5, RecurRule::MODE_CLONE, $rrule, 0, 0, false, 'alice');
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

	public function testSpawnCloneAllDayCarriesFlagAndSlidesTheSingleDate(): void {
		// An all-day card is a single day (just an End date, at UTC midnight). Its
		// clone keeps the all-day flag and slides that one date to the occurrence -
		// no start date is invented, so the clone stays a clean single all-day day.
		$dayTs = (new \DateTimeImmutable('2027-01-15T00:00:00Z'))->getTimestamp();
		$template = $this->templateCard();
		$template->setAllDay(true);
		$template->setDuedate(new \DateTime('@' . $dayTs));
		$rule = $this->rule(mode: RecurRule::MODE_CLONE, rrule: 'FREQ=DAILY', nextOccurrenceAt: $dayTs + 86400);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c) use ($dayTs): Card {
				self::assertTrue($c->getAllDay());
				self::assertNull($c->getStartDate());
				self::assertSame($dayTs + 86400, $c->getDuedate()->getTimestamp());
				return $c;
			});

		$this->service->spawn($rule);
	}

	public function testSpawnResetAllDayKeepsFlagAndSlidesTheSingleDate(): void {
		// RESET on an all-day card slides its single day forward and leaves the
		// all-day flag on (the reset card is its own template).
		$dayTs = (new \DateTimeImmutable('2027-01-15T00:00:00Z'))->getTimestamp();
		$rule = $this->rule(mode: RecurRule::MODE_RESET, rrule: 'FREQ=DAILY', nextOccurrenceAt: $dayTs + 86400);
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);

		$moved = $this->templateCard();
		$moved->setStackId(5);
		$moved->setAllDay(true);
		$moved->setDuedate(new \DateTime('@' . $dayTs));
		$this->cardService->expects(self::once())->method('move')->willReturn($moved);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c) use ($dayTs): Card {
				self::assertTrue($c->getAllDay());
				self::assertNull($c->getStartDate());
				self::assertSame($dayTs + 86400, $c->getDuedate()->getTimestamp());
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

	public function testSpawnResetClearsStartedAtSoTheCardIsNotStuckInProgress(): void {
		// A card's status is the (started_at, done_at) PAIR. The reset cleared only
		// done_at, so the returning occurrence read "In progress" from the moment it
		// came back - forever, and for every later occurrence - even though nobody
		// had started it. Only its predecessor had.
		$rule = $this->rule(mode: RecurRule::MODE_RESET, nextOccurrenceAt: self::NOW);
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);

		$moved = $this->templateCard();
		$moved->setStackId(5);
		$moved->setDoneAt(self::NOW);
		$moved->setStartedAt(self::NOW - 7 * 86400); // started during the last cycle
		$this->cardService->expects(self::once())
			->method('move')
			->with(10, 5, null, 'alice')
			->willReturn($moved);

		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (Card $c): Card {
				self::assertSame(0, $c->getDoneAt());
				self::assertSame(0, $c->getStartedAt(), 'the reset occurrence has not been started');
				return $c;
			});

		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$this->service->spawn($rule);
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

	/**
	 * A RESET rule with COUNT=3 delivers EXACTLY three cards and then stops for
	 * good. RESET rewrites the template card's own dates to each fired occurrence,
	 * so the DTSTART the next spawn expands from walks forward with the series;
	 * because every computeNextOccurrence() call builds a fresh iterator, the
	 * COUNT window used to restart on that moved anchor and the rule repeated
	 * forever. The tally on the rule is what bounds it now.
	 *
	 * The exhaustion assertions are the load-bearing half: "spawned 3" alone would
	 * pass even unfixed, because runDueRules stops once the cursor passes now. The
	 * rule must be provably OUT of occurrences, not merely waiting for the clock -
	 * unfixed this run produces a 4th card and leaves the rule armed for tomorrow.
	 */
	public function testResetCountLimitedSeriesStopsAfterItsLastOccurrence(): void {
		// Series origin 3 days back → occurrences at t-3d, t-2d, t-1d. A 4th daily
		// step would land exactly on now, so an unbounded rule spawns it too.
		$origin = self::NOW - 3 * 86400;
		$rule = $this->rule(mode: RecurRule::MODE_RESET, rrule: 'FREQ=DAILY;COUNT=3', nextOccurrenceAt: $origin);

		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $origin));

		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		// One shared instance for every read: spawnReset mutates the template's
		// dates and the next spawn re-reads its anchor from it, so the same object
		// reproduces the real anchor drift.
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardService->method('move')->willReturn($template);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$starts = [];
		$this->cardMapper->method('update')->willReturnCallback(static function (Card $c) use (&$starts): Card {
			$starts[] = $c->getStartDate()?->getTimestamp();
			return $c;
		});

		$spawned = $this->service->runDueRules();

		self::assertSame(3, $spawned);
		// RESET still slides the template's window to each occurrence, unchanged.
		self::assertSame([$origin, $origin + 86400, $origin + 2 * 86400], $starts);
		self::assertSame(3, $rule->getOccurrencesSpawned());
		// Exhausted for good - not just "not due yet".
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}

	/**
	 * The cap has to be read exactly the way sabre reads it. `COUNT=+3` is a value
	 * the iterator happily accepts as 3, but a stricter digits-only read would
	 * hand back "no COUNT" - and precisely those rules would keep running away.
	 */
	public function testResetSeriesIsBoundedForACountSpellingSabreAccepts(): void {
		$origin = self::NOW - 3 * 86400;
		$rule = $this->rule(mode: RecurRule::MODE_RESET, rrule: 'FREQ=DAILY;COUNT=+3', nextOccurrenceAt: $origin);
		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $origin));

		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardService->method('move')->willReturn($template);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		self::assertSame(3, $this->service->runDueRules());
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}

	/**
	 * The short COUNT-limited RESET series were never broken - the drifted anchor
	 * happens to burn two iterator steps per spawn, so COUNT=1 and COUNT=2 already
	 * terminated on their own. They must keep terminating on exactly their own
	 * last occurrence: the guard has to agree with the iterator, not pre-empt it.
	 *
	 * @testWith [1]
	 *           [2]
	 */
	public function testShortCountLimitedResetSeriesStillYieldExactlyCountCards(int $count): void {
		$origin = self::NOW - 3 * 86400;
		$rule = $this->rule(
			mode: RecurRule::MODE_RESET,
			rrule: 'FREQ=DAILY;COUNT=' . $count,
			nextOccurrenceAt: $origin,
		);
		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $origin));

		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardService->method('move')->willReturn($template);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		self::assertSame($count, $this->service->runDueRules());
		self::assertSame($count, $rule->getOccurrencesSpawned());
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}

	/**
	 * A manual "create now" DOES spend one of the COUNT slots - it stamps a real
	 * card, and "ends after 3 times" means three cards however they were produced.
	 * This is the one behaviour the guard changes for CLONE, so pin it down.
	 */
	public function testCreateNowConsumesACountSlot(): void {
		// Two of three already delivered; the manual fire is the third and last.
		$rule = $this->rule(rrule: 'FREQ=DAILY;COUNT=3', nextOccurrenceAt: self::NOW, occurrencesSpawned: 2);
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		self::assertNotNull($this->service->createNow(3, 'alice'));
		self::assertSame(3, $rule->getOccurrencesSpawned());
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}

	/**
	 * The same COUNT=3 series in CLONE mode still yields exactly three cards. CLONE
	 * mutates the spawned copy, never the template, so its DTSTART never moved and
	 * the iterator already exhausted correctly - the guard must AGREE with it at
	 * the same occurrence, never double-count and cut the series short.
	 */
	public function testCloneCountLimitedSeriesStillYieldsExactlyCountCards(): void {
		$origin = self::NOW - 3 * 86400;
		$rule = $this->rule(rrule: 'FREQ=DAILY;COUNT=3', nextOccurrenceAt: $origin);

		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $origin));

		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$id = 300;
		$this->cardService->method('create')->willReturnCallback(function () use (&$id): Card {
			return $this->spawnedCard($id++);
		});

		self::assertSame(3, $this->service->runDueRules());
		self::assertSame(3, $rule->getOccurrencesSpawned());
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}

	/**
	 * UNTIL-limited RESET rules are untouched by the COUNT guard: UNTIL is an
	 * absolute cut-off, independent of DTSTART and of any tally, so it already
	 * ended the series correctly and must keep doing so.
	 */
	public function testResetUntilLimitedSeriesIsUnchanged(): void {
		$origin = self::NOW - 3 * 86400;
		// Last allowed occurrence is yesterday → t-3d, t-2d, t-1d, then done.
		$rrule = 'FREQ=DAILY;UNTIL=' . gmdate('Ymd\THis\Z', self::NOW - 86400);
		$rule = $this->rule(mode: RecurRule::MODE_RESET, rrule: $rrule, nextOccurrenceAt: $origin);

		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $origin));

		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardService->method('move')->willReturn($template);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		self::assertSame(3, $this->service->runDueRules());
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}

	/**
	 * A skipped occurrence must not trip the COUNT guard: the guard reads
	 * occurrences_spawned, and a skip produced no card, so it deliberately leaves
	 * that untouched and the rule stays armed for the occurrence after it.
	 *
	 * Note what this does NOT claim. A skip still advances the cursor past its
	 * occurrence (pre-existing behaviour, untouched here), so the skipped
	 * occurrence is spent: this COUNT=3 series ends having delivered 2 cards, not
	 * 3. The guard's job is only to avoid charging a slot for a card that was
	 * never created - it cannot hand the occurrence back.
	 */
	public function testSkipWhileOpenDoesNotTripTheCountGuard(): void {
		// One of three delivered at t-1d; the cursor sits on the second of the
		// three occurrences (t-1d, now, t+1d).
		$rule = $this->rule(
			rrule: 'FREQ=DAILY;COUNT=3',
			skipWhileOpen: true,
			nextOccurrenceAt: self::NOW,
			lastSpawnedAt: 42,
			occurrencesSpawned: 1,
		);
		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . (self::NOW - 86400)));
		$openCard = $this->spawnedCard(42); // still open → this occurrence is skipped
		$this->cardMapper->method('find')->willReturnCallback(
			static fn (int $id): Card => $id === 42 ? $openCard : $template
		);
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);
		$this->cardService->method('create')->willReturn($this->spawnedCard(400));

		self::assertNull($this->service->spawn($rule));
		// The guard did not fire: the tally is untouched and the rule is still
		// armed, for the third occurrence rather than being retired here.
		self::assertSame(1, $rule->getOccurrencesSpawned());
		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
		self::assertTrue($rule->getEnabled());

		// The previous card is closed, so the last occurrence really does spawn,
		// and only then is the series done - 2 cards delivered out of COUNT=3,
		// because the skipped occurrence was spent, not returned.
		$openCard->setDoneAt(self::NOW - 10);
		self::assertNotNull($this->service->spawn($rule));
		self::assertSame(2, $rule->getOccurrencesSpawned());
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}

	/**
	 * A trashed-template pause does not spend a COUNT slot either - same reasoning
	 * as a skip: no card was delivered, so the series still owes the user one.
	 */
	public function testTrashedTemplatePauseDoesNotConsumeACountSlot(): void {
		$rule = $this->rule(rrule: 'FREQ=DAILY;COUNT=3', nextOccurrenceAt: self::NOW, occurrencesSpawned: 2);
		$trashed = $this->templateCard();
		$trashed->setStartDate(new \DateTime('@' . (self::NOW - 86400)));
		$trashed->setDeletedAt(self::NOW - 5);
		$this->cardMapper->method('find')->with(10)->willReturn($trashed);
		$this->cardService->expects(self::never())->method('create');
		$this->ruleMapper->expects(self::once())->method('update')->willReturnArgument(0);

		self::assertNull($this->service->spawn($rule));
		self::assertSame(2, $rule->getOccurrencesSpawned());
		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
		self::assertTrue($rule->getEnabled());
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

	/**
	 * The "before" case: a rule whose next fire is still in the FUTURE spawns
	 * nothing. runDueRules re-checks next_occurrence_at <= now in its own loop
	 * (not only in the query), so a fresh #80-safe rule that somehow reaches the
	 * loop is still left untouched - no card, no schedule advance.
	 */
	public function testRunDueRulesSkipsRuleNotYetDue(): void {
		$rule = $this->rule(nextOccurrenceAt: self::NOW + 86400); // fires tomorrow
		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardService->expects(self::never())->method('create');
		$this->cardService->expects(self::never())->method('move');
		$this->ruleMapper->expects(self::never())->method('update');

		self::assertSame(0, $this->service->runDueRules());
		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
	}

	/**
	 * An exhausted rule (next_occurrence_at == 0, e.g. a COUNT/UNTIL series that
	 * has run out) spawns nothing even if it reaches the loop - the > 0 guard
	 * holds independently of the query.
	 */
	public function testRunDueRulesSkipsExhaustedRule(): void {
		$rule = $this->rule(nextOccurrenceAt: 0);
		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardService->expects(self::never())->method('create');

		self::assertSame(0, $this->service->runDueRules());
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
	 * Editing a long-running rule to "repeat weekly, 4 times" means FOUR MORE
	 * cards. `occurrences_spawned` is a lifetime tally and the COUNT guard reads it
	 * raw, so without a reset the first spawn after the edit saw 13 >= 4, zeroed
	 * the cursor and switched the rule off - one card instead of four, silently.
	 */
	public function testEditingTheRepeatToAddACountStartsTheTallyOver(): void {
		// A weekly RESET rule that has been running for months: 12 cards so far.
		$origin = self::NOW - 20 * 7 * 86400;
		$rule = $this->rule(
			mode: RecurRule::MODE_RESET,
			rrule: 'FREQ=WEEKLY',
			nextOccurrenceAt: self::NOW + 86400,
			occurrencesSpawned: 12,
		);
		$template = $this->templateCard();
		$template->setStartDate(new \DateTime('@' . $origin));

		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		// One shared template instance: RESET slides its dates and the next spawn
		// re-reads the anchor from it, so this reproduces the real anchor drift.
		$this->cardMapper->method('find')->with(10)->willReturn($template);
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->service->update(3, null, null, null, 'FREQ=WEEKLY;COUNT=4', null, null, null, null, 'alice');

		self::assertSame(0, $rule->getOccurrencesSpawned());

		// Replay the series from its origin and count the cards it actually makes.
		$rule->setNextOccurrenceAt($origin);
		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('findLastInStack')->with(5)->willReturn(null);
		$this->cardService->method('move')->willReturn($template);
		$this->cardMapper->method('update')->willReturnArgument(0);

		self::assertSame(4, $this->service->runDueRules());
		self::assertSame(4, $rule->getOccurrencesSpawned());
		// ...and only THEN does it retire itself.
		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
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

	/**
	 * Pointing a rule at a DIFFERENT card re-anchors it on that card's dates. The
	 * schedule is anchored on the template's own Start date, so a rule left on the
	 * old card's anchor would keep firing on dates that have nothing to do with the
	 * card it now stamps - silently, until some unrelated schedule edit re-armed it.
	 */
	public function testUpdateRepointingTheTemplateCardReArmsFromTheNewCardsAnchor(): void {
		$newStart = self::NOW + 5 * 86400;
		$newTemplate = $this->templateCard(11);
		$newTemplate->setStartDate(new \DateTime('@' . $newStart));

		// Cursor still sits on the OLD card's schedule (tomorrow).
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 86400);
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->willReturnMap([
			[10, $this->templateCard()],
			[11, $newTemplate],
		]);
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->method('update')->willReturnArgument(0);

		// Only the template card changes - no RRULE, timezone or enabled edit.
		$this->service->update(3, 11, null, null, null, null, null, null, null, 'alice');

		self::assertSame(11, $rule->getTemplateCardId());
		self::assertSame($newStart, $rule->getNextOccurrenceAt());
	}

	/**
	 * The #65 regression guard for the re-point re-arm above: an update that changes
	 * NOTHING must still leave the cursor exactly where it was, never rewound onto an
	 * occurrence the cron already spawned.
	 */
	public function testUpdateChangingNothingLeavesTheCursorUntouched(): void {
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 86400);
		$this->wireUpdate($rule);

		$this->service->update(3, null, null, null, null, null, null, null, null, 'alice');

		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
	}

	/**
	 * Only the target stack changed - where the copies land, not when the rule
	 * fires - so the cursor stays put. Guards the re-point re-arm from widening
	 * into "recompute on any change".
	 */
	public function testUpdateChangingOnlyTheTargetStackLeavesTheCursorUntouched(): void {
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 86400);
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->willReturnMap([
			[5, $this->stack()],
			[6, $this->stack(6)],
		]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->service->update(3, null, 6, null, null, null, null, null, null, 'alice');

		self::assertSame(6, $rule->getTargetStackId());
		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
	}

	// ---- re-arm on a card date edit ---------------------------------------

	/**
	 * Editing a repeating card's Start date re-points the series so it follows the
	 * new date - what a user naturally expects when they reschedule it. A Start
	 * moved to five days out makes the next fire land on that date.
	 */
	public function testRearmForTemplateCardRepointsScheduleToNewStartDate(): void {
		$newStart = self::NOW + 5 * 86400;
		$card = $this->templateCard(); // id 10, matches the rule's templateCardId
		$card->setStartDate(new \DateTime('@' . $newStart));

		// The stored cursor is stale (3 days ago) until the edit re-arms it.
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW - 3 * 86400);
		$this->ruleMapper->method('findByTemplateCard')->with(10)->willReturn([$rule]);
		$this->ruleMapper->expects(self::once())
			->method('update')
			->willReturnCallback(static function (RecurRule $r) use ($newStart): RecurRule {
				self::assertSame($newStart, $r->getNextOccurrenceAt());
				return $r;
			});

		$this->service->rearmForTemplateCard($card);
	}

	/**
	 * A disabled rule is left alone by a date edit - re-pointing a paused schedule
	 * would silently resurrect it.
	 */
	public function testRearmForTemplateCardSkipsDisabledRule(): void {
		$card = $this->templateCard();
		$card->setStartDate(new \DateTime('@' . (self::NOW + 5 * 86400)));

		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW - 3 * 86400);
		$rule->setEnabled(false);
		$this->ruleMapper->method('findByTemplateCard')->with(10)->willReturn([$rule]);
		$this->ruleMapper->expects(self::never())->method('update');

		$this->service->rearmForTemplateCard($card);
	}

	/**
	 * The re-arm runs AFTER the card edit has committed, so a failing rule write
	 * must never bubble out - otherwise it would 500 an edit that already
	 * succeeded. The failure is logged and swallowed.
	 */
	public function testRearmForTemplateCardSwallowsAFailingRuleUpdate(): void {
		$card = $this->templateCard();
		$card->setStartDate(new \DateTime('@' . (self::NOW + 5 * 86400)));

		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW - 3 * 86400);
		$this->ruleMapper->method('findByTemplateCard')->with(10)->willReturn([$rule]);
		$this->ruleMapper->method('update')->willThrowException(new \RuntimeException('db down'));
		$this->logger->expects(self::atLeastOnce())->method('warning');

		// Must not throw despite the rule write failing.
		$this->service->rearmForTemplateCard($card);
	}

	// ---- explicit timezone -------------------------------------------------

	/**
	 * An explicit IANA timezone from the caller (API/MCP clients scheduling for a
	 * zone that is not the creator's own) wins over the owner's personal timezone.
	 */
	public function testCreateHonoursAnExplicitTimezoneOverTheOwnerDefault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('America/New_York');
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
			self::assertSame('Europe/Istanbul', $r->getTimezone());
			$r->setId(9);
			return $r;
		});

		$service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'alice', 'Europe/Istanbul');
	}

	/**
	 * An empty timezone means "not supplied" - it falls back to the owner default
	 * rather than storing a blank zone.
	 */
	public function testCreateWithEmptyTimezoneFallsBackToTheOwnerDefault(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('America/New_York');
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

		$service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', RecurRule::POLICY_AT_OCCURRENCE, 0, false, 'alice', '');
	}

	/**
	 * A garbage zone id is a client error (400), NOT a silent fall-back to the
	 * server default - a schedule quietly expanded in the wrong zone fires at the
	 * wrong wall-clock hour forever.
	 */
	public function testCreateRejectsAnInvalidTimezone(): void {
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::never())->method('insert');

		$this->expectException(InvalidInputException::class);
		$this->service->create(1, 10, 5, RecurRule::MODE_CLONE, 'FREQ=DAILY', 0, 0, false, 'alice', 'Mars/Olympus_Mons');
	}

	/**
	 * Re-anchoring the same RRULE in a DIFFERENT zone shifts every future
	 * occurrence, so it counts as a schedule change and re-arms the cursor.
	 */
	public function testUpdateWithChangedTimezoneReArmsCursor(): void {
		// Cursor parked on a stale value that is NOT a daily occurrence, so a
		// re-arm is observable (a no-op edit would leave it exactly as-is).
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 50_000);
		$this->wireUpdate($rule);

		$this->service->update(3, null, null, null, null, null, null, null, null, 'alice', 'Europe/Istanbul');

		self::assertSame('Europe/Istanbul', $rule->getTimezone());
		self::assertSame(self::NOW + 86400, $rule->getNextOccurrenceAt());
	}

	/**
	 * Re-sending the zone the rule already carries is a no-op edit: it must not
	 * re-arm the cursor (same duplicate-clone risk as an unchanged RRULE, #65).
	 */
	public function testUpdateWithUnchangedTimezoneDoesNotReArmCursor(): void {
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 50_000);
		$rule->setTimezone('Europe/Istanbul');
		$this->wireUpdate($rule);

		$this->service->update(3, null, null, null, null, null, null, null, null, 'alice', 'Europe/Istanbul');

		self::assertSame(self::NOW + 50_000, $rule->getNextOccurrenceAt());
	}

	/**
	 * A null timezone leaves the rule's zone alone (it is not clearable - a rule
	 * always expands in some zone).
	 */
	public function testUpdateWithNullTimezoneLeavesTheZoneUntouched(): void {
		$rule = $this->rule(rrule: 'FREQ=DAILY', nextOccurrenceAt: self::NOW + 50_000);
		$rule->setTimezone('Europe/Istanbul');
		$this->wireUpdate($rule);

		$this->service->update(3, null, null, null, null, null, null, null, null, 'alice', null);

		self::assertSame('Europe/Istanbul', $rule->getTimezone());
		self::assertSame(self::NOW + 50_000, $rule->getNextOccurrenceAt());
	}

	public function testUpdateRejectsAnInvalidTimezone(): void {
		$rule = $this->rule();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(3, null, null, null, null, null, null, null, null, 'alice', 'Mars/Olympus_Mons');
	}

	/**
	 * Editing an existing rule down to a FREQ-less RRULE is the same 400 as
	 * creating one - and, crucially, the same non-hang. update() re-arms the cursor
	 * through the same expansion path create() validates with, so the guard has to
	 * hold on both or a live board can be edited into a wedged worker.
	 *
	 * @testWith [""]
	 *           ["   "]
	 *           ["COUNT=3"]
	 *           ["INTERVAL=2"]
	 */
	public function testUpdateRejectsAFreqLessRruleWith400(string $rrule): void {
		$rule = $this->rule();
		$this->ruleMapper->method('find')->with(3)->willReturn($rule);
		$this->boardMapper->method('find')->with(1)->willReturn($this->board());
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->stackMapper->method('find')->with(5)->willReturn($this->stack());
		$this->ruleMapper->expects(self::never())->method('update');

		$this->expectException(InvalidInputException::class);
		$this->service->update(3, null, null, null, $rrule, null, null, null, null, 'alice', null);
	}

	/**
	 * A FREQ-less rule STORED before the guard landed (create/update accepted
	 * 'COUNT=3' happily, and the API's own default for an omitted rrule was '')
	 * must not wedge the cron pass that picks it up. runDueRules() reaches it
	 * through advanceSchedule(), which now gets an InvalidInputException instead of
	 * an endless walk, logs it, and retires the rule.
	 *
	 * This is the path with no timeout at all - occ/cron runs under the CLI SAPI,
	 * where max_execution_time is 0 - so it is the one that matters most.
	 *
	 * @testWith [""]
	 *           ["COUNT=3"]
	 *           ["INTERVAL=2"]
	 */
	public function testRunDueRulesDisablesAStoredFreqLessRuleInsteadOfSpinning(string $rrule): void {
		$rule = $this->rule(rrule: $rrule, nextOccurrenceAt: self::NOW);
		$this->ruleMapper->method('findDueEnabled')->willReturn([$rule]);
		$this->cardMapper->method('find')->with(10)->willReturn($this->templateCard());
		$this->cardService->method('create')->willReturn($this->spawnedCard());
		$this->cardMapper->method('update')->willReturnArgument(0);
		$this->cardLabelMapper->method('findLabelIdsByCard')->willReturn([]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn([]);
		$this->ruleMapper->method('update')->willReturnArgument(0);

		$this->service->runDueRules();

		self::assertSame(0, $rule->getNextOccurrenceAt());
		self::assertFalse($rule->getEnabled());
	}
}

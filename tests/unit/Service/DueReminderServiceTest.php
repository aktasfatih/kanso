<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Access\BoardAccess;
use OCA\Kanso\Access\NotAMemberException;
use OCA\Kanso\Access\ViewerContext;
use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\BoardMapper;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\Change;
use OCA\Kanso\Db\SubscriptionMapper;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\CardVisibilityScope;
use OCA\Kanso\Service\ChangeNotifier;
use OCA\Kanso\Service\DueReminderService;
use OCA\Kanso\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DueReminderServiceTest extends TestCase {
	private const NOW = 1_800_000_000;

	private CardMapper&MockObject $cardMapper;
	private CardAssigneeMapper&MockObject $cardAssigneeMapper;
	private SubscriptionMapper&MockObject $subscriptionMapper;
	private NotificationService&MockObject $notificationService;
	private BoardMapper&MockObject $boardMapper;
	private BoardAccess&MockObject $boardAccess;
	private ITimeFactory&MockObject $time;
	private LoggerInterface&MockObject $logger;
	private ChangeNotifier&MockObject $changeNotifier;
	private DueReminderService $service;

	/**
	 * The audience's resolved roles on board 1, consumed by the REAL
	 * CardVisibilityGuard + CardVisibilityScope pair the service is wired
	 * with - so the leak tests exercise the actual visibility rule.
	 *
	 * @var array<string, string>
	 */
	private array $rolesOnBoard = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->subscriptionMapper = $this->createMock(SubscriptionMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->boardMapper = $this->createMock(BoardMapper::class);
		// Id-agnostic: most tests use board 1, but the push-batching tests sweep
		// cards across two boards in one run.
		$this->boardMapper->method('find')->willReturnCallback(static function (int $id): Board {
			$board = new Board();
			$board->setId($id);
			$board->setOwner('board-owner');
			return $board;
		});
		$this->boardAccess = $this->createMock(BoardAccess::class);
		$this->boardAccess->method('rolesOn')->willReturnCallback(
			fn (Board $b, array $uids): array => array_intersect_key($this->rolesOnBoard, array_flip($uids)),
		);
		$this->boardAccess->method('contextFor')->willReturnCallback(
			function (Board $b, string $uid): ViewerContext {
				$role = $this->rolesOnBoard[$uid] ?? null;
				if ($role === null) {
					throw new NotAMemberException('not a member');
				}
				return ViewerContext::forMember($uid, $b->getId(), $role, false);
			},
		);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->changeNotifier = $this->createMock(ChangeNotifier::class);
		$this->service = new DueReminderService(
			$this->cardMapper,
			$this->cardAssigneeMapper,
			$this->subscriptionMapper,
			$this->notificationService,
			$this->boardMapper,
			new CardVisibilityGuard($this->boardAccess, new CardVisibilityScope()),
			$this->time,
			$this->logger,
			$this->changeNotifier,
		);
	}

	private function card(
		int $id,
		?int $dueTs,
		bool $dayBefore = false,
		int $dueSent = 0,
		int $dayBeforeSent = 0,
		int $boardId = 1,
	): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId($boardId);
		$card->setStackId(5);
		$card->setDoneAt(0);
		$card->setArchived(false);
		$card->setDeletedAt(0);
		$card->setDuedate($dueTs === null ? null : new \DateTime('@' . $dueTs));
		$card->setDueReminderDayBefore($dayBefore);
		$card->setDueReminderSent($dueSent);
		$card->setDayBeforeReminderSent($dayBeforeSent);
		return $card;
	}

	// ---- at-due path: notifies assignees + watchers once -------------------

	public function testDueCardNotifiesAssigneesAndWatchersOnce(): void {
		$card = $this->card(10, self::NOW - 60);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->with(10)->willReturn(['alice', 'bob']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(10)->willReturn(['bob', 'carol']);

		// Union deduped: alice, bob, carol - each once, at-due (daysBefore 0).
		$notified = [];
		$this->notificationService->expects(self::exactly(3))
			->method('notifyCardDue')
			->willReturnCallback(function (int $cardId, string $uid, int $daysBefore) use (&$notified): void {
				self::assertSame(10, $cardId);
				self::assertSame(0, $daysBefore);
				$notified[] = $uid;
			});

		// The at-due marker is stamped so a re-run is a no-op.
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Card $c): Card {
				self::assertSame(self::NOW, $c->getDueReminderSent());
				return $c;
			});

		self::assertSame(1, $this->service->runDueReminders());
		sort($notified);
		self::assertSame(['alice', 'bob', 'carol'], $notified);
	}

	public function testSecondRunDoesNotReNotify(): void {
		// The card already has its at-due marker set - it must not re-notify. In
		// practice the query would exclude it, but the service re-checks markers.
		$card = $this->card(10, self::NOW - 60, dueSent: self::NOW - 30);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);

		$this->notificationService->expects(self::never())->method('notifyCardDue');
		$this->cardMapper->expects(self::never())->method('update');

		self::assertSame(0, $this->service->runDueReminders());
	}

	// ---- re-arm: due date moved forward -----------------------------------

	public function testMovedDueDateReArmsReminder(): void {
		// A card whose due date moved to the future (marker cleared by
		// CardService::update) is a candidate again but not yet due - no fire.
		$future = $this->card(10, self::NOW + 3600, dueSent: 0);
		$this->cardMapper->method('findDueForReminder')->willReturn([$future]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);

		$this->notificationService->expects(self::never())->method('notifyCardDue');
		$this->cardMapper->expects(self::never())->method('update');

		self::assertSame(0, $this->service->runDueReminders());
	}

	public function testReArmedCardFiresOnceItsNewDueTimePasses(): void {
		// Same card, now past its (moved) due time with a cleared marker - fires.
		$card = $this->card(10, self::NOW - 1, dueSent: 0);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);

		$this->notificationService->expects(self::once())
			->method('notifyCardDue')->with(10, 'alice', 0);
		$this->cardMapper->expects(self::once())->method('update');

		self::assertSame(1, $this->service->runDueReminders());
	}

	// ---- skip done/archived/no-duedate ------------------------------------

	public function testNoDuedateCardIsSkipped(): void {
		$card = $this->card(10, null);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);

		$this->notificationService->expects(self::never())->method('notifyCardDue');
		$this->cardMapper->expects(self::never())->method('update');

		self::assertSame(0, $this->service->runDueReminders());
	}

	public function testEmptyCandidateSetIsNoOp(): void {
		$this->cardMapper->method('findDueForReminder')->willReturn([]);
		$this->notificationService->expects(self::never())->method('notifyCardDue');
		self::assertSame(0, $this->service->runDueReminders());
	}

	// ---- day-before path --------------------------------------------------

	public function testDayBeforeFiresWhenEnabled(): void {
		// Due in ~12h, opted-in, day-before unsent, at-due not yet due.
		$card = $this->card(10, self::NOW + 43200, dayBefore: true);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);

		$this->notificationService->expects(self::once())
			->method('notifyCardDue')->with(10, 'alice', 1);
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Card $c): Card {
				self::assertSame(self::NOW, $c->getDayBeforeReminderSent());
				// The at-due marker stays unset - it is not yet due.
				self::assertSame(0, $c->getDueReminderSent());
				return $c;
			});

		self::assertSame(1, $this->service->runDueReminders());
	}

	public function testDayBeforeNotFiredWhenDisabled(): void {
		// Due in ~12h but not opted-in, and not yet at due time - nothing fires.
		$card = $this->card(10, self::NOW + 43200, dayBefore: false);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);

		$this->notificationService->expects(self::never())->method('notifyCardDue');
		$this->cardMapper->expects(self::never())->method('update');

		self::assertSame(0, $this->service->runDueReminders());
	}

	public function testDayBeforeAndAtDueBothFireForAPastDueOptedInCard(): void {
		// A past-due card that also opted into day-before, both markers unsent:
		// both reminders fire (distinct daysBefore), each marker stamped.
		$card = $this->card(10, self::NOW - 10, dayBefore: true);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);

		$days = [];
		$this->notificationService->expects(self::exactly(2))
			->method('notifyCardDue')
			->willReturnCallback(function (int $cardId, string $uid, int $daysBefore) use (&$days): void {
				$days[] = $daysBefore;
			});
		$this->cardMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Card $c): Card {
				self::assertSame(self::NOW, $c->getDueReminderSent());
				self::assertSame(self::NOW, $c->getDayBeforeReminderSent());
				return $c;
			});

		self::assertSame(1, $this->service->runDueReminders());
		sort($days);
		self::assertSame([0, 1], $days);
	}

	// ---- resilience: one bad card doesn't abort the run -------------------

	public function testOneFailingCardIsLoggedAndDoesNotAbortRun(): void {
		$bad = $this->card(10, self::NOW - 60);
		$good = $this->card(11, self::NOW - 60);
		$this->cardMapper->method('findDueForReminder')->willReturn([$bad, $good]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')
			->willReturnCallback(function (int $cardId): array {
				if ($cardId === 10) {
					throw new \RuntimeException('assignee lookup blew up');
				}
				return ['alice'];
			});
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);

		$this->logger->expects(self::once())->method('warning');
		// The good card still notifies + stamps.
		$this->notificationService->expects(self::once())->method('notifyCardDue')->with(11, 'alice', 0);
		$this->cardMapper->expects(self::once())->method('update');

		self::assertSame(1, $this->service->runDueReminders());
	}

	// ---- visibility (#3760): a hidden card reminds no one outside it --------

	public function testHiddenCardRemindsOnlyRecipientsInsideItsVisibility(): void {
		// A provider-internal card watched/assigned across the fence: the
		// external watcher and the unresolvable uid get NO reminder - a bell
		// entry would be an existence oracle for a card they cannot open.
		$card = $this->card(10, self::NOW - 60);
		$card->setVisibility(CardVisibilityScope::VISIBILITY_INTERNAL);
		$card->setCreatorRole(ViewerContext::ROLE_INTERNAL);
		$card->setOwner('inty');
		$this->rolesOnBoard = [
			'inty' => ViewerContext::ROLE_INTERNAL,
			'exty' => ViewerContext::ROLE_EXTERNAL,
		];
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->with(10)->willReturn(['inty', 'exty']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(10)->willReturn(['ghost']);

		$this->notificationService->expects(self::once())
			->method('notifyCardDue')->with(10, 'inty', 0);
		// The marker still stamps: the reminder FIRED, just to a scoped audience.
		$this->cardMapper->expects(self::once())->method('update');

		self::assertSame(1, $this->service->runDueReminders());
	}

	// ---- change log: the stamp is a board-payload mutation ------------------

	/**
	 * Stamping a marker rewrites CardMapper::SUMMARY_COLUMNS (`due_reminder_sent`,
	 * `day_before_reminder_sent`, `last_modified`), so the sweep mutates the board
	 * payload. Without a change row the board ETag never moves and a delta-sync
	 * client keeps serving the pre-reminder card indefinitely. Actor is null - the
	 * cron acts for nobody.
	 */
	public function testFiredReminderRecordsACardChangeRow(): void {
		$card = $this->card(10, self::NOW - 60);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);
		$this->cardMapper->method('update')->willReturnArgument(0);

		$this->changeNotifier->expects(self::once())
			->method('recordChange')
			->with(1, Change::ENTITY_CARD, 10, Change::ACTION_UPDATE, null);
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);

		self::assertSame(1, $this->service->runDueReminders());
	}

	/**
	 * The guard that keeps the row above honest: a candidate that owes nothing
	 * writes no card row, so it must write no change row either - a sweep that
	 * changed nothing must not move the ETag or wake a single client.
	 */
	public function testCandidateThatFiresNothingRecordsNoChangeAndNoPush(): void {
		// Due in ~12h, day-before not opted in - nothing owed yet.
		$card = $this->card(10, self::NOW + 43200, dayBefore: false);
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);

		$this->changeNotifier->expects(self::never())->method('recordChange');
		$this->changeNotifier->expects(self::never())->method('pushBoardChanged');

		self::assertSame(0, $this->service->runDueReminders());
	}

	/**
	 * The batching contract. A run may stamp up to MAX_PER_RUN cards and
	 * pushBoardChanged() fans out per board participant, so one push per card would
	 * turn a routine tick into hundreds of identical queue writes. The change ROWS
	 * stay per card (delta sync needs them individually); the PUSH collapses to one
	 * per board, emitted after the sweep.
	 */
	public function testTwoCardsOnOneBoardRecordTwoRowsButPushOnce(): void {
		$this->cardMapper->method('findDueForReminder')->willReturn([
			$this->card(10, self::NOW - 60),
			$this->card(11, self::NOW - 60),
		]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);
		$this->cardMapper->method('update')->willReturnArgument(0);

		$rows = [];
		$this->changeNotifier->expects(self::exactly(2))
			->method('recordChange')
			->willReturnCallback(function (int $boardId, int $entityType, int $entityId, int $action, ?string $actor) use (&$rows): Change {
				self::assertSame(Change::ENTITY_CARD, $entityType);
				self::assertSame(Change::ACTION_UPDATE, $action);
				self::assertNull($actor);
				$rows[] = [$boardId, $entityId];
				return new Change();
			});
		$this->changeNotifier->expects(self::once())->method('pushBoardChanged')->with(1);

		self::assertSame(2, $this->service->runDueReminders());
		self::assertSame([[1, 10], [1, 11]], $rows);
	}

	public function testCardsOnDifferentBoardsPushOnceEach(): void {
		$this->cardMapper->method('findDueForReminder')->willReturn([
			$this->card(10, self::NOW - 60),
			$this->card(11, self::NOW - 60, boardId: 2),
		]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->willReturn(['alice']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->willReturn([]);
		$this->cardMapper->method('update')->willReturnArgument(0);

		$pushed = [];
		$this->changeNotifier->expects(self::exactly(2))
			->method('pushBoardChanged')
			->willReturnCallback(function (int $boardId) use (&$pushed): void {
				$pushed[] = $boardId;
			});

		self::assertSame(2, $this->service->runDueReminders());
		sort($pushed);
		self::assertSame([1, 2], $pushed);
	}

	public function testPrivateCardRemindsOnlyItsOwner(): void {
		$card = $this->card(10, self::NOW - 60);
		$card->setVisibility(CardVisibilityScope::VISIBILITY_PRIVATE);
		$card->setCreatorRole(ViewerContext::ROLE_INTERNAL);
		$card->setOwner('inty');
		$this->rolesOnBoard = [
			'inty' => ViewerContext::ROLE_INTERNAL,
			'mgr' => ViewerContext::ROLE_INTERNAL, // manager is NOT a backdoor
		];
		$this->cardMapper->method('findDueForReminder')->willReturn([$card]);
		$this->cardAssigneeMapper->method('findUserIdsByCard')->with(10)->willReturn(['inty', 'mgr']);
		$this->subscriptionMapper->method('findCardSubscriberUids')->with(10)->willReturn([]);

		$this->notificationService->expects(self::once())
			->method('notifyCardDue')->with(10, 'inty', 0);

		self::assertSame(1, $this->service->runDueReminders());
	}
}

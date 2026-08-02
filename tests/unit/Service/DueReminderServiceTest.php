<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardAssigneeMapper;
use OCA\Kanso\Db\CardMapper;
use OCA\Kanso\Db\SubscriptionMapper;
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
	private ITimeFactory&MockObject $time;
	private LoggerInterface&MockObject $logger;
	private DueReminderService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->cardMapper = $this->createMock(CardMapper::class);
		$this->cardAssigneeMapper = $this->createMock(CardAssigneeMapper::class);
		$this->subscriptionMapper = $this->createMock(SubscriptionMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new DueReminderService(
			$this->cardMapper,
			$this->cardAssigneeMapper,
			$this->subscriptionMapper,
			$this->notificationService,
			$this->time,
			$this->logger,
		);
	}

	private function card(
		int $id,
		?int $dueTs,
		bool $dayBefore = false,
		int $dueSent = 0,
		int $dayBeforeSent = 0,
	): Card {
		$card = new Card();
		$card->setId($id);
		$card->setBoardId(1);
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
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Db\Board;
use OCA\Kanso\Db\Card;
use OCA\Kanso\Db\CardRunningTimer;
use OCA\Kanso\Db\CardRunningTimerMapper;
use OCA\Kanso\Db\Project;
use OCA\Kanso\Db\ProjectCard;
use OCA\Kanso\Db\ProjectCardMapper;
use OCA\Kanso\Db\ProjectMapper;
use OCA\Kanso\Db\Reminder;
use OCA\Kanso\Db\ReminderMapper;
use OCA\Kanso\Db\Subscription;
use OCA\Kanso\Db\SubscriptionMapper;
use OCA\Kanso\Service\CardMovePersonalService;
use OCA\Kanso\Service\CardVisibilityGuard;
use OCA\Kanso\Service\InvalidInputException;
use OCA\Kanso\Service\PermissionService;
use PHPUnit\Framework\TestCase;

class CardMovePersonalServiceTest extends TestCase {
	public function testTransfersPersonalDataAndOneRunningTimerWithoutDuplicates(): void {
		$reminders = $this->createMock(ReminderMapper::class);
		$subscriptions = $this->createMock(SubscriptionMapper::class);
		$memberships = $this->createMock(ProjectCardMapper::class);
		$projects = $this->createMock(ProjectMapper::class);
		$timers = $this->createMock(CardRunningTimerMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$visibility = $this->createMock(CardVisibilityGuard::class);
		$permissions->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$visibility->method('isVisible')->willReturn(true);

		$reminder = new Reminder();
		$reminder->setId(1);
		$reminder->setUserId('alice');
		$reminder->setCardId(5);
		$reminder->setCommentId(10);
		$reminder->setRemindAt(1000);
		$reminder->setFiredAt(null);
		$reminder->setCreatedAt(100);
		$reminders->method('findByCardForMove')->with(5)->willReturn([$reminder]);
		$reminders->expects(self::once())->method('update')->willReturnCallback(function (Reminder $r): Reminder {
			self::assertSame(6, $r->getCardId());
			self::assertSame(20, $r->getCommentId());
			self::assertSame(1000, $r->getRemindAt());
			self::assertNull($r->getFiredAt());
			self::assertSame(100, $r->getCreatedAt());
			return $r;
		});

		$thread = new Subscription();
		$thread->setCardId(5);
		$thread->setSubscriber('bob');
		$thread->setCommentThreadId(10);
		$thread->setState(Subscription::STATE_OPTED_OUT);
		$thread->setCreatedAt(200);
		$watcher = new Subscription();
		$watcher->setCardId(5);
		$watcher->setSubscriber('alice');
		$watcher->setCommentThreadId(SubscriptionMapper::THREAD_CARD);
		$watcher->setState(Subscription::STATE_SUBSCRIBED);
		$subscriptions->method('findByCardForMove')->willReturn([$thread, $watcher]);
		$subscriptions->expects(self::once())->method('update')->willReturnCallback(function (Subscription $s): Subscription {
			self::assertSame(6, $s->getCardId());
			self::assertSame(20, $s->getCommentThreadId());
			self::assertSame(Subscription::STATE_OPTED_OUT, $s->getState());
			self::assertSame(200, $s->getCreatedAt());
			return $s;
		});

		$membership = new ProjectCard();
		$membership->setProjectId(9);
		$membership->setCardId(5);
		$membership->setAddedAt(300);
		$memberships->method('findByCardForMove')->willReturn([$membership]);
		$project = new Project();
		$project->setOwner('carol');
		$projects->method('find')->with(9)->willReturn($project);
		$memberships->expects(self::once())->method('update')->willReturnCallback(function (ProjectCard $p): ProjectCard {
			self::assertSame(6, $p->getCardId());
			self::assertSame(300, $p->getAddedAt());
			return $p;
		});

		$timer = new CardRunningTimer();
		$timer->setCardId(5);
		$timer->setBoardId(1);
		$timer->setStartedBy('dana');
		$timer->setStartedAt(400);
		$timers->method('findByCard')->with(5)->willReturn($timer);
		$timers->expects(self::once())->method('update')->willReturnCallback(function (CardRunningTimer $t): CardRunningTimer {
			self::assertSame(6, $t->getCardId());
			self::assertSame(2, $t->getBoardId());
			self::assertSame(400, $t->getStartedAt());
			return $t;
		});
		$target = new Card();
		$target->setId(6);
		$board = new Board();
		$board->setId(2);

		(new CardMovePersonalService($reminders, $subscriptions, $memberships, $projects, $timers, $permissions, $visibility))
			->transfer(5, $target, $board, [10 => 20]);
	}

	public function testRejectsInaccessibleReminderOwnerWithoutMovingAnyState(): void {
		$reminders = $this->createMock(ReminderMapper::class);
		$subscriptions = $this->createMock(SubscriptionMapper::class);
		$memberships = $this->createMock(ProjectCardMapper::class);
		$projects = $this->createMock(ProjectMapper::class);
		$timers = $this->createMock(CardRunningTimerMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$visibility = $this->createMock(CardVisibilityGuard::class);
		$permissions->method('getPermissions')->willReturn(0);
		$reminder = new Reminder();
		$reminder->setUserId('alice');
		$reminders->method('findByCardForMove')->willReturn([$reminder]);
		$reminders->expects(self::never())->method('update');
		$subscriptions->expects(self::never())->method('update');
		$memberships->expects(self::never())->method('update');
		$timers->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		(new CardMovePersonalService($reminders, $subscriptions, $memberships, $projects, $timers, $permissions, $visibility))
			->transfer(5, new Card(), new Board(), []);
	}

	public function testRejectsUnmappedCommentReminder(): void {
		$reminders = $this->createMock(ReminderMapper::class);
		$subscriptions = $this->createMock(SubscriptionMapper::class);
		$memberships = $this->createMock(ProjectCardMapper::class);
		$projects = $this->createMock(ProjectMapper::class);
		$timers = $this->createMock(CardRunningTimerMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$visibility = $this->createMock(CardVisibilityGuard::class);
		$permissions->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$visibility->method('isVisible')->willReturn(true);
		$reminder = new Reminder();
		$reminder->setUserId('alice');
		$reminder->setCommentId(10);
		$reminders->method('findByCardForMove')->willReturn([$reminder]);
		$reminders->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		(new CardMovePersonalService($reminders, $subscriptions, $memberships, $projects, $timers, $permissions, $visibility))
			->transfer(5, new Card(), new Board(), []);
	}

	public function testRejectsThreadSubscriberWhoCannotSeeDestinationCard(): void {
		$reminders = $this->createMock(ReminderMapper::class);
		$subscriptions = $this->createMock(SubscriptionMapper::class);
		$memberships = $this->createMock(ProjectCardMapper::class);
		$projects = $this->createMock(ProjectMapper::class);
		$timers = $this->createMock(CardRunningTimerMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$visibility = $this->createMock(CardVisibilityGuard::class);
		$permissions->method('getPermissions')->willReturn(PermissionService::PERMISSION_READ);
		$visibility->method('isVisible')->willReturn(false);
		$subscription = new Subscription();
		$subscription->setSubscriber('alice');
		$subscription->setCommentThreadId(10);
		$subscriptions->method('findByCardForMove')->willReturn([$subscription]);
		$subscriptions->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		(new CardMovePersonalService($reminders, $subscriptions, $memberships, $projects, $timers, $permissions, $visibility))
			->transfer(5, new Card(), new Board(), [10 => 20]);
	}

	/** @return iterable<string, array{int, bool}> */
	public static function inaccessibleTimerOwners(): iterable {
		yield 'no board permission' => [0, true];
		yield 'card hidden' => [PermissionService::PERMISSION_READ, false];
	}

	/** @dataProvider inaccessibleTimerOwners */
	public function testRejectsRunningTimerOwnerWhoCannotAccessDestinationCard(int $permission, bool $visible): void {
		$reminders = $this->createMock(ReminderMapper::class);
		$subscriptions = $this->createMock(SubscriptionMapper::class);
		$memberships = $this->createMock(ProjectCardMapper::class);
		$projects = $this->createMock(ProjectMapper::class);
		$timers = $this->createMock(CardRunningTimerMapper::class);
		$permissions = $this->createMock(PermissionService::class);
		$visibility = $this->createMock(CardVisibilityGuard::class);
		$permissions->method('getPermissions')->willReturn($permission);
		$visibility->method('isVisible')->willReturn($visible);
		$timer = new CardRunningTimer();
		$timer->setCardId(5);
		$timer->setStartedBy('alice');
		$timers->method('findByCard')->with(5)->willReturn($timer);
		$timers->expects(self::never())->method('update');
		$reminders->expects(self::never())->method('update');
		$subscriptions->expects(self::never())->method('update');
		$memberships->expects(self::never())->method('update');
		$this->expectException(InvalidInputException::class);
		(new CardMovePersonalService($reminders, $subscriptions, $memberships, $projects, $timers, $permissions, $visibility))
			->transfer(5, new Card(), new Board(), []);
	}
}

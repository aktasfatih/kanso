<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\NotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase {
	private IManager&MockObject $manager;
	private IGroupManager&MockObject $groupManager;
	private NotificationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->manager = $this->createMock(IManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new NotificationService($this->manager, $this->groupManager);
	}

	/** Wires the `admin` group to contain exactly these uids. */
	private function stubAdmins(string ...$uids): void {
		$users = [];
		foreach ($uids as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$this->groupManager->method('get')->with('admin')->willReturn($group);
	}

	public function testNotifyCardAssignedBuildsAndSends(): void {
		$n = $this->createMock(INotification::class);
		// Each fluent setter configured exactly once (asserted) or stubbed once.
		$n->expects(self::once())->method('setApp')->with('kanso')->willReturnSelf();
		$n->expects(self::once())->method('setUser')->with('bob')->willReturnSelf();
		$n->method('setDateTime')->willReturnSelf();
		$n->expects(self::once())->method('setObject')->with('card', '9')->willReturnSelf();
		$n->expects(self::once())->method('setSubject')
			->with('card_assigned', ['actor' => 'alice', 'cardId' => 9])
			->willReturnSelf();

		$this->manager->method('createNotification')->willReturn($n);
		$this->manager->expects(self::once())->method('notify')->with($n);

		$this->service->notifyCardAssigned(9, 'bob', 'alice');
	}

	public function testNotifyCardAssignedIsNoOpWhenActorAssignsThemselves(): void {
		$this->manager->expects(self::never())->method('createNotification');
		$this->manager->expects(self::never())->method('notify');

		$this->service->notifyCardAssigned(9, 'alice', 'alice');
	}

	public function testNotifyStepAssignedIsKeyedByTheItemAndCarriesTheCard(): void {
		// Steps notify per ITEM (object checklist_item/50) so several steps of
		// one card dismiss independently; the card id rides in the parameters
		// for render-time resolution (#3745).
		$n = $this->createMock(INotification::class);
		$n->expects(self::once())->method('setApp')->with('kanso')->willReturnSelf();
		$n->expects(self::once())->method('setUser')->with('client')->willReturnSelf();
		$n->method('setDateTime')->willReturnSelf();
		$n->expects(self::once())->method('setObject')->with('checklist_item', '50')->willReturnSelf();
		$n->expects(self::once())->method('setSubject')
			->with('step_assigned', ['actor' => 'alice', 'cardId' => 9])
			->willReturnSelf();

		$this->manager->method('createNotification')->willReturn($n);
		$this->manager->expects(self::once())->method('notify')->with($n);

		$this->service->notifyStepAssigned(50, 9, 'client', 'alice');
	}

	public function testNotifyStepAssignedIsNoOpWhenActorAssignsThemselves(): void {
		$this->manager->expects(self::never())->method('createNotification');
		$this->manager->expects(self::never())->method('notify');

		$this->service->notifyStepAssigned(50, 9, 'alice', 'alice');
	}

	public function testDismissStepAssignedMarksProcessedByItem(): void {
		$n = $this->createMock(INotification::class);
		$n->method('setApp')->willReturnSelf();
		$n->method('setUser')->willReturnSelf();
		$n->expects(self::once())->method('setObject')->with('checklist_item', '50')->willReturnSelf();
		$n->expects(self::once())->method('setSubject')->with('step_assigned')->willReturnSelf();

		$this->manager->method('createNotification')->willReturn($n);
		$this->manager->expects(self::once())->method('markProcessed')->with($n);
		$this->manager->expects(self::never())->method('notify');

		$this->service->dismissStepAssigned(50, 'client');
	}

	public function testDismissCardAssignedMarksProcessed(): void {
		$n = $this->createMock(INotification::class);
		$n->method('setApp')->willReturnSelf();
		$n->method('setUser')->willReturnSelf();
		$n->expects(self::once())->method('setObject')->with('card', '9')->willReturnSelf();
		$n->expects(self::once())->method('setSubject')->with('card_assigned')->willReturnSelf();

		$this->manager->method('createNotification')->willReturn($n);
		$this->manager->expects(self::once())->method('markProcessed')->with($n);
		$this->manager->expects(self::never())->method('notify');

		$this->service->dismissCardAssigned(9, 'bob');
	}

	public function testDismissAllForObjectsClearsOneObjectPerCallForEveryUser(): void {
		// The board-purge sweep: app + object type + object id is the ENTIRE
		// predicate. No user (every recipient's copy has to go) and no subject
		// (every kind of notification for that object has to go) - but the app
		// and the id pin it to Kanso's own objects, which is what keeps it from
		// reaching another app's or another board's notifications.
		$built = [];
		$this->manager->method('createNotification')
			->willReturnCallback(function () use (&$built): INotification {
				$n = $this->createMock(INotification::class);
				$n->expects(self::once())->method('setApp')->with('kanso')->willReturnSelf();
				$n->expects(self::once())->method('setObject')
					->willReturnCallback(function (string $type, string $id) use ($n, &$built): INotification {
						$built[] = $type . '/' . $id;
						return $n;
					});
				$n->expects(self::never())->method('setUser');
				$n->expects(self::never())->method('setSubject');
				return $n;
			});
		$this->manager->expects(self::exactly(3))->method('markProcessed');
		$this->manager->expects(self::never())->method('notify');

		$this->service->dismissAllForObjects(NotificationService::OBJECT_CARD, [9, 10, 11]);

		self::assertSame(['card/9', 'card/10', 'card/11'], $built);
	}

	public function testDismissAllForObjectsIsANoOpForAnEmptyIdSet(): void {
		// A board with no cards must not fire a user-less, id-less
		// markProcessed() - that would match every Kanso notification there is.
		$this->manager->expects(self::never())->method('createNotification');
		$this->manager->expects(self::never())->method('markProcessed');

		$this->service->dismissAllForObjects(NotificationService::OBJECT_CARD, []);
	}

	// ---- backup run notifications (#161) ----------------------------------

	public function testNotifyBackupResultFansOutToEveryAdmin(): void {
		$this->stubAdmins('root', 'ops');

		$users = [];
		$subjects = [];
		$this->manager->method('createNotification')->willReturnCallback(
			function () use (&$users, &$subjects): INotification {
				$n = $this->createMock(INotification::class);
				$n->method('setApp')->willReturnSelf();
				$n->method('setUser')->willReturnCallback(function (string $uid) use ($n, &$users): INotification {
					$users[] = $uid;
					return $n;
				});
				$n->method('setDateTime')->willReturnSelf();
				$n->method('setObject')->with('backup', 'run')->willReturnSelf();
				$n->method('setSubject')->willReturnCallback(
					function (string $subject, array $params) use ($n, &$subjects): INotification {
						$subjects[] = [$subject, $params];
						return $n;
					}
				);
				return $n;
			}
		);
		// One supersede + one post per admin.
		$this->manager->expects(self::exactly(2))->method('markProcessed');
		$this->manager->expects(self::exactly(2))->method('notify');

		$this->service->notifyBackupResult(false, 'Backup target path is unset or unwritable');

		// Two notifications built per admin (the supersede probe + the real one).
		self::assertSame(['root', 'root', 'ops', 'ops'], $users);
		self::assertSame([
			['backup_failed', ['message' => 'Backup target path is unset or unwritable']],
			['backup_failed', ['message' => 'Backup target path is unset or unwritable']],
		], $subjects);
	}

	public function testNotifyBackupResultUsesTheSuccessSubjectWhenTheRunWorked(): void {
		$this->stubAdmins('root');

		$subjects = [];
		$this->manager->method('createNotification')->willReturnCallback(
			function () use (&$subjects): INotification {
				$n = $this->createMock(INotification::class);
				$n->method('setApp')->willReturnSelf();
				$n->method('setUser')->willReturnSelf();
				$n->method('setDateTime')->willReturnSelf();
				$n->method('setObject')->willReturnSelf();
				$n->method('setSubject')->willReturnCallback(
					function (string $subject) use ($n, &$subjects): INotification {
						$subjects[] = $subject;
						return $n;
					}
				);
				return $n;
			}
		);

		$this->service->notifyBackupResult(true, 'Backed up 4 board(s)');

		self::assertSame([NotificationService::SUBJECT_BACKUP_OK], $subjects);
	}

	public function testNotifyBackupResultSupersedesTheAdminsPreviousEntry(): void {
		// The supersede probe must be keyed on app + user + the backup object and
		// carry NO subject, so it clears a previous `backup_ok` as readily as a
		// previous `backup_failed`. Without that, a nightly failure would stack
		// one unread entry per night.
		$this->stubAdmins('root');

		$probe = $this->createMock(INotification::class);
		$probe->method('setApp')->with('kanso')->willReturnSelf();
		$probe->method('setUser')->with('root')->willReturnSelf();
		$probe->expects(self::once())->method('setObject')->with('backup', 'run')->willReturnSelf();
		$probe->expects(self::never())->method('setSubject');

		$posted = $this->createMock(INotification::class);
		$posted->method('setApp')->willReturnSelf();
		$posted->method('setUser')->willReturnSelf();
		$posted->method('setDateTime')->willReturnSelf();
		$posted->method('setObject')->willReturnSelf();
		$posted->method('setSubject')->willReturnSelf();

		$this->manager->method('createNotification')->willReturnOnConsecutiveCalls($probe, $posted);
		$this->manager->expects(self::once())->method('markProcessed')->with($probe);
		$this->manager->expects(self::once())->method('notify')->with($posted);

		$this->service->notifyBackupResult(false, 'boom');
	}

	public function testNotifyBackupResultIsANoOpWithoutAnAdminGroup(): void {
		// No recipient may be guessed for an instance-wide event.
		$this->groupManager->method('get')->with('admin')->willReturn(null);
		$this->manager->expects(self::never())->method('createNotification');
		$this->manager->expects(self::never())->method('notify');
		$this->manager->expects(self::never())->method('markProcessed');

		$this->service->notifyBackupResult(false, 'boom');
	}

	public function testNotifyBackupResultIsANoOpWhenTheAdminGroupIsEmpty(): void {
		$this->stubAdmins();
		$this->manager->expects(self::never())->method('createNotification');
		$this->manager->expects(self::never())->method('notify');

		$this->service->notifyBackupResult(true, 'Backed up 0 board(s)');
	}
}

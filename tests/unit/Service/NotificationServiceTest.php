<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Service\NotificationService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase {
	private IManager&MockObject $manager;
	private NotificationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->manager = $this->createMock(IManager::class);
		$this->service = new NotificationService($this->manager);
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
}

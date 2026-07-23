<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\SpawnRecurringCards;
use OCA\Kanso\Service\RecurrenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SpawnRecurringCardsTest extends TestCase {
	private ITimeFactory&MockObject $time;
	private RecurrenceService&MockObject $recurrenceService;
	private SpawnRecurringCards $job;

	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_800_000_000);
		$this->recurrenceService = $this->createMock(RecurrenceService::class);
		$this->job = new SpawnRecurringCards($this->time, $this->recurrenceService);
	}

	private function runJob(): void {
		$run = new \ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, null);
	}

	public function testRunDelegatesToRunDueRules(): void {
		$this->recurrenceService->expects(self::once())
			->method('runDueRules')
			->willReturn(4);

		$this->runJob();
	}

	public function testRunIsThinDelegatorWhenNothingDue(): void {
		// The job must do nothing beyond calling runDueRules (the per-rule
		// error handling and batching live in RecurrenceService).
		$this->recurrenceService->expects(self::once())
			->method('runDueRules')
			->willReturn(0);

		$this->runJob();
	}
}

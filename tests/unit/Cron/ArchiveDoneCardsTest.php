<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\ArchiveDoneCards;
use OCA\Kanso\Service\ArchiveService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ArchiveDoneCardsTest extends TestCase {
	private ITimeFactory&MockObject $time;
	private ArchiveService&MockObject $archiveService;
	private ArchiveDoneCards $job;

	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_800_000_000);
		$this->archiveService = $this->createMock(ArchiveService::class);
		$this->job = new ArchiveDoneCards($this->time, $this->archiveService);
	}

	private function runJob(): void {
		$run = new \ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, null);
	}

	public function testRunDelegatesToRunEnabledRules(): void {
		$this->archiveService->expects(self::once())
			->method('runEnabledRules')
			->willReturn(7);

		$this->runJob();
	}

	public function testRunSwallowsNothing_delegationOnly(): void {
		// The job is a thin delegator: it must not do anything beyond calling
		// runEnabledRules() (the batch cap lives in ArchiveService::sweep).
		$this->archiveService->expects(self::once())
			->method('runEnabledRules')
			->willReturn(0);

		$this->runJob();
	}
}

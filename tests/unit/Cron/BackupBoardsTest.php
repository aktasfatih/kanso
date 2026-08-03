<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\BackupBoards;
use OCA\Kanso\Service\BackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BackupBoardsTest extends TestCase {
	private ITimeFactory&MockObject $time;
	private BackupService&MockObject $backupService;
	private BackupBoards $job;

	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_800_000_000);
		$this->backupService = $this->createMock(BackupService::class);
		$this->job = new BackupBoards($this->time, $this->backupService);
	}

	public function testRunDelegatesToBackupService(): void {
		// The cron is a thin delegator: it must only call run() (the enabled
		// gate, per-board isolation and retention all live in BackupService).
		$this->backupService->expects(self::once())
			->method('run')
			->willReturn(['status' => 'ok', 'boards' => 3, 'failures' => 0, 'message' => 'Backed up 3 board(s)']);

		$run = new \ReflectionMethod($this->job, 'run');
		$run->invoke($this->job, null);
	}
}

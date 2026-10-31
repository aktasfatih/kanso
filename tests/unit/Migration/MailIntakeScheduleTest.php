<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Migration;

use OCA\Kanso\Cron\PollMailIntake;
use OCA\Kanso\Migration\Version007300Date20261031000000;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class MailIntakeScheduleTest extends TestCase {
	public function testUpgradeReplacesTheOldJobRegistration(): void {
		$jobs = $this->createMock(IJobList::class);
		$order = [];
		$jobs->expects(self::once())->method('remove')
			->with(PollMailIntake::class)->willReturnCallback(static function () use (&$order): void {
				$order[] = 'remove';
			});
		$jobs->expects(self::once())->method('add')
			->with(PollMailIntake::class)->willReturnCallback(static function () use (&$order): void {
				$order[] = 'add';
			});

		$migration = new Version007300Date20261031000000($jobs);
		$migration->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);

		self::assertSame(['remove', 'add'], $order);
	}
}

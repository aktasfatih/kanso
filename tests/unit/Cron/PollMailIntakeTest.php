<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\PollMailIntake;
use OCA\Kanso\Service\MailIntakeService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class PollMailIntakeTest extends TestCase {
	public function testPollIsTimeSensitiveAndRunsAtFiveMinuteIntervals(): void {
		$job = new PollMailIntake(
			$this->createMock(ITimeFactory::class),
			$this->createMock(MailIntakeService::class),
		);

		self::assertTrue($job->isTimeSensitive());
		self::assertSame(300, $job->getInterval());
	}
}

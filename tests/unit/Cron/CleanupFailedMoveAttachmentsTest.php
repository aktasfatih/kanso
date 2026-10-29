<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Cron;

use OCA\Kanso\Cron\CleanupFailedMoveAttachments;
use OCA\Kanso\Service\CardAttachmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupFailedMoveAttachmentsTest extends TestCase {
	public function testFailedCleanupIsRetried(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$attachments = $this->createMock(CardAttachmentService::class);
		$objects = [['cardId' => 42, 'key' => str_repeat('a', 32)]];
		$attachments->expects(self::once())->method('deleteUnreferencedMoveObjects')->with($objects)->willReturn(1);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::once())->method('scheduleAfter')->with(CleanupFailedMoveAttachments::class, 4600, ['objects' => $objects]);
		$job = new CleanupFailedMoveAttachments($time, $attachments, $jobs, $this->createMock(LoggerInterface::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, ['objects' => $objects]);
	}

	public function testInvalidObjectIsNeverDeleted(): void {
		$attachments = $this->createMock(CardAttachmentService::class);
		$attachments->expects(self::never())->method('deleteUnreferencedMoveObjects');
		$job = new CleanupFailedMoveAttachments(
			$this->createMock(ITimeFactory::class),
			$attachments,
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class),
		);
		(new \ReflectionMethod($job, 'run'))->invoke($job, ['objects' => [['cardId' => 42, 'key' => '../not-a-key']]]);
	}
}

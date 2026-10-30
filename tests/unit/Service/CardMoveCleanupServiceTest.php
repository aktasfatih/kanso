<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Service;

use OCA\Kanso\Cron\CleanupFailedMoveAttachments;
use OCA\Kanso\Service\CardAttachmentService;
use OCA\Kanso\Service\CardMoveCleanupService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

class CardMoveCleanupServiceTest extends TestCase {
	public function testSchedulesBoundedCleanupJobs(): void {
		$jobs = $this->createMock(IJobList::class);
		$chunks = [];
		$jobs->expects(self::exactly(2))->method('add')->willReturnCallback(static function ($job, $argument) use (&$chunks): void {
			self::assertSame(CleanupFailedMoveAttachments::class, $job);
			$chunks[] = $argument['objects'];
		});
		$service = new CardMoveCleanupService($jobs, $this->createMock(CardAttachmentService::class));
		$objects = array_fill(0, 51, ['cardId' => PHP_INT_MAX, 'key' => str_repeat('a', 32)]);
		$service->schedule($objects);
		self::assertSame([50, 1], array_map('count', $chunks));
		self::assertLessThanOrEqual(4000, strlen((string)json_encode(['objects' => $chunks[0]])));
	}

	public function testCleanupReportsStorageFailures(): void {
		$attachments = $this->createMock(CardAttachmentService::class);
		$objects = [['cardId' => 42, 'key' => str_repeat('a', 32)]];
		$attachments->expects(self::once())->method('deleteUnreferencedMoveObjects')->with($objects)->willReturn(1);
		$service = new CardMoveCleanupService($this->createMock(IJobList::class), $attachments);
		$this->expectExceptionMessage('Could not clean up attachments');
		$service->cleanup($objects);
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * CSV import writes up to {@see \OCA\Kanso\Service\CsvImportService::MAX_ROWS}
 * cards per request, each an INSERT plus a change-log row inside one transaction,
 * so it carries the same per-user rate limit as the board-import endpoint next
 * door. Asserted on the ATTRIBUTE, because that is where the app framework reads
 * it - and only that a limit exists, so the numbers stay tunable without a red
 * test.
 */
class CsvImportControllerTest extends TestCase {
	public function testImportCarriesAPerUserRateLimit(): void {
		$method = new \ReflectionMethod(CsvImportController::class, 'import');
		$attributes = $method->getAttributes(UserRateLimit::class);

		self::assertCount(1, $attributes, 'import() must declare a UserRateLimit');
		$limit = $attributes[0]->newInstance();
		self::assertGreaterThan(0, $limit->getLimit());
		self::assertGreaterThan(0, $limit->getPeriod());
	}
}

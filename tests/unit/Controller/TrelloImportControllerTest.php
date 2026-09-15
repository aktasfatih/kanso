<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Trello import turns one client-supplied document into a whole board of rows in
 * a single request, exactly like the board-import endpoint it mirrors, so it
 * carries the same per-user rate limit - leaving it off would just be the open
 * door beside the closed one. Asserted on the ATTRIBUTE, because that is where
 * the app framework reads it - and only that a limit exists, so the numbers stay
 * tunable without a red test.
 */
class TrelloImportControllerTest extends TestCase {
	public function testImportCarriesAPerUserRateLimit(): void {
		$method = new \ReflectionMethod(TrelloImportController::class, 'import');
		$attributes = $method->getAttributes(UserRateLimit::class);

		self::assertCount(1, $attributes, 'import() must declare a UserRateLimit');
		$limit = $attributes[0]->newInstance();
		self::assertGreaterThan(0, $limit->getLimit());
		self::assertGreaterThan(0, $limit->getPeriod());
	}
}

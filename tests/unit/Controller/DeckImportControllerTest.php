<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Deck import writes a whole board - stacks, cards, labels, assignments,
 * comments - and copies every attachment's bytes out of Deck's storage, in ONE
 * request, so it carries a per-user rate limit like the other import paths.
 * Asserted on the ATTRIBUTE, because that is where the app framework reads it -
 * and only that a limit exists, so the numbers stay tunable without a red test.
 */
class DeckImportControllerTest extends TestCase {
	public function testImportCarriesAPerUserRateLimit(): void {
		$method = new \ReflectionMethod(DeckImportController::class, 'import');
		$attributes = $method->getAttributes(UserRateLimit::class);

		self::assertCount(1, $attributes, 'import() must declare a UserRateLimit');
		$limit = $attributes[0]->newInstance();
		self::assertGreaterThan(0, $limit->getLimit());
		self::assertGreaterThan(0, $limit->getPeriod());
	}

	/**
	 * Listing the importable boards is a cheap read - a fixed handful of queries
	 * regardless of board count, with no per-board fan-out. Pinned so a later
	 * blanket-limiting sweep has to argue with a test rather than quietly throttle
	 * the picker a migration reopens once per board.
	 */
	public function testBoardListingIsNotRateLimited(): void {
		$method = new \ReflectionMethod(DeckImportController::class, 'index');

		self::assertCount(0, $method->getAttributes(UserRateLimit::class), 'index() must stay unlimited');
	}
}

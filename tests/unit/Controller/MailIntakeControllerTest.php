<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

class MailIntakeControllerTest extends TestCase {
	public function testConnectionHasPerUserRateLimit(): void {
		$method = new \ReflectionMethod(MailIntakeController::class, 'test');
		$attributes = $method->getAttributes(UserRateLimit::class);

		self::assertCount(1, $attributes);
		$limit = $attributes[0]->newInstance();
		self::assertSame(10, $limit->getLimit());
		self::assertSame(300, $limit->getPeriod());
	}
}

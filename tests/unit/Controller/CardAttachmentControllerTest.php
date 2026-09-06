<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Controller;

use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Both attachment WRITE paths land bytes in the app's own app-data, so both carry
 * the same per-user rate limit - limiting only the multipart upload would leave
 * the "attach from Files" copy as the open door beside the closed one. Asserted on
 * the ATTRIBUTE (that is where the app framework reads it) and only that a limit
 * exists, so the numbers stay tunable without a red test.
 */
class CardAttachmentControllerTest extends TestCase {
	/**
	 * @return array<string, array{string}>
	 */
	public static function writePaths(): array {
		return [
			'multipart upload' => ['create'],
			'copy from Files' => ['createFromFile'],
		];
	}

	/**
	 * @dataProvider writePaths
	 */
	public function testEveryAttachmentWritePathCarriesAPerUserRateLimit(string $method): void {
		$reflected = new \ReflectionMethod(CardAttachmentController::class, $method);
		$attributes = $reflected->getAttributes(UserRateLimit::class);

		self::assertCount(1, $attributes, $method . '() must declare a UserRateLimit');
		$limit = $attributes[0]->newInstance();
		self::assertGreaterThan(0, $limit->getLimit());
		self::assertGreaterThan(0, $limit->getPeriod());
	}

	/**
	 * The limit belongs on the WRITE paths only: reads are cheap and a board with
	 * many attachments would otherwise 429 an ordinary viewer scrolling it.
	 *
	 * @dataProvider readPaths
	 */
	public function testReadPathsAreNotRateLimited(string $method): void {
		$reflected = new \ReflectionMethod(CardAttachmentController::class, $method);

		self::assertCount(0, $reflected->getAttributes(UserRateLimit::class), $method . '() must stay unlimited');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function readPaths(): array {
		return [
			'list' => ['index'],
			'download' => ['download'],
			'inline render' => ['inline'],
		];
	}
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

class BooleanColumnCompatibilityTest extends TestCase {
	public function testBooleanColumnsAllowNullForNextcloudCompatibility(): void {
		$files = glob(__DIR__ . '/../../../lib/Migration/Version*.php');
		self::assertNotEmpty($files);

		$violations = [];
		$columnCount = 0;
		foreach ($files as $file) {
			$source = file_get_contents($file);
			self::assertNotFalse($source);
			preg_match_all("/->addColumn\\(\\s*'([^']+)'\\s*,\\s*Types::BOOLEAN\\s*,\\s*\\[(.*?)\\]\\s*\\)/s", $source, $matches, PREG_SET_ORDER);
			foreach ($matches as $match) {
				$columnCount++;
				if (!preg_match("/'notnull'\\s*=>\\s*false\\b/", $match[2])) {
					$violations[] = basename($file) . ': ' . $match[1];
				}
			}
		}

		self::assertGreaterThan(0, $columnCount, 'No boolean columns found in migrations');
		self::assertSame([], $violations, "Boolean columns must be nullable for Nextcloud compatibility:\n" . implode("\n", $violations));
	}
}

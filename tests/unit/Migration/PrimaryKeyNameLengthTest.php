<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Guards against the "Primary index name on X is too long" install failure.
 *
 * Nextcloud 30-32 enforce a length limit on the AUTO-GENERATED name of a
 * table's PRIMARY KEY. A table whose physical name (the `oc_` prefix + the
 * logical name passed to createTable) is long enough pushes that derived name
 * over the limit, so `occ maintenance:install` aborts and NONE of the app's
 * tables are created. NC 33/34 relaxed the check, so this only reproduces on
 * the NC 30-32 install matrix — a ~1.5h feedback loop. Every table at risk
 * must therefore give its primary key an explicit short name
 * (`$table->setPrimaryKey(['id'], 'kanso_<abbrev>_pk')`).
 *
 * This is a static source scan (no DB needed) so it runs in the fast unit job
 * and catches the whole class of bug on every PR, before the slow matrix does.
 * It reproduces what surfaced #3d1620c (comment_reactions et al.) and the later
 * kanso_card_field_values regression.
 */
class PrimaryKeyNameLengthTest extends TestCase {
	/**
	 * Physical table names at or above this length must not rely on the default
	 * primary-key name. 20 is deliberately conservative: the two known real
	 * failures were 26 and 23 chars, and the fix convention already named
	 * 20-char tables defensively.
	 */
	private const PHYSICAL_LENGTH_LIMIT = 20;

	/** The dev/default Nextcloud table prefix that createTable() names get. */
	private const PREFIX = 'oc_';

	public function testEveryLongTableNamesItsPrimaryKey(): void {
		$dir = __DIR__ . '/../../../lib/Migration';
		$files = glob($dir . '/Version*.php');
		self::assertNotEmpty($files, 'no migration files found');

		$violations = [];
		foreach ($files as $file) {
			$src = file_get_contents($file);
			self::assertNotFalse($src, "could not read $file");

			// Walk createTable(...) and setPrimaryKey(...) calls in source order.
			$pattern = "/createTable\\(\\s*'([a-z0-9_]+)'\\s*\\)"
				. "|setPrimaryKey\\(\\s*\\[[^\\]]*\\]\\s*(?:,\\s*'([a-z0-9_]+)')?\\s*\\)/";
			preg_match_all($pattern, $src, $matches, PREG_SET_ORDER);

			$currentTable = null;
			foreach ($matches as $m) {
				// A setPrimaryKey(['id']) (no explicit name) match populates neither
				// capture group, so group 1 is absent — coalesce before comparing.
				$isCreateTable = ($m[1] ?? '') !== '';
				if ($isCreateTable) {
					$currentTable = $m[1];
					continue;
				}
				$explicitName = $m[2] ?? '';
				$physical = self::PREFIX . (string)$currentTable;
				if ($explicitName === '' && strlen($physical) >= self::PHYSICAL_LENGTH_LIMIT) {
					$violations[] = sprintf(
						'%s: table "%s" (physical %d chars) uses a default primary key; '
							. "give it setPrimaryKey(['id'], 'kanso_<abbrev>_pk')",
						basename($file),
						$physical,
						strlen($physical)
					);
				}
			}
		}

		self::assertSame(
			[],
			$violations,
			"Primary-key names would overflow NC 30-32's limit and fail install:\n"
				. implode("\n", $violations)
		);
	}
}

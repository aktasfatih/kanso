<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * Board-background validation (#3528). A board's background is stored as a small
 * CURATED preset KEY, never free-form CSS - the key is the only thing that
 * crosses the trust boundary, so there is no CSS-injection surface. The frontend
 * maps the key to the actual gradient; the server only guarantees the stored
 * value is one of the known keys (or null = "no background").
 *
 * Mirrors {@see ColorValidator}: a `final` static helper that returns the
 * normalized value to store or throws {@see InvalidInputException}.
 */
final class BackgroundValidator {
	/**
	 * The curated allow-list of preset keys. Kept in lockstep with the frontend
	 * palette in `src/services/backgrounds.js`. A value outside this set is
	 * rejected; null / '' means "no background".
	 */
	public const PRESETS = [
		'sunset',
		'ocean',
		'forest',
		'lavender',
		'peach',
		'slate',
		'aurora',
		'ember',
	];

	/**
	 * Null and the empty string both mean "no background" (an empty string in an
	 * update clears it).
	 *
	 * @return ?string the normalized preset key to store, or null
	 * @throws InvalidInputException when the value is not an allow-listed preset
	 */
	public static function assertValid(?string $background): ?string {
		if ($background === null || $background === '') {
			return null;
		}
		if (!in_array($background, self::PRESETS, true)) {
			throw new InvalidInputException('Unknown board background');
		}
		return $background;
	}
}

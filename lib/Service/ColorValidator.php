<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

/**
 * Shared color validation for boards and labels: colors are stored as
 * 6-digit hex values without the leading "#".
 */
final class ColorValidator {
	/**
	 * Null and the empty string both mean "no color" (an empty string in an
	 * update clears the color).
	 *
	 * @return ?string the normalized value to store
	 * @throws InvalidInputException
	 */
	public static function assertValid(?string $color): ?string {
		if ($color === null || $color === '') {
			return null;
		}
		if (preg_match('/^[0-9A-Fa-f]{6}$/', $color) !== 1) {
			throw new InvalidInputException('Color must be a 6-digit hex value without "#"');
		}
		return $color;
	}
}

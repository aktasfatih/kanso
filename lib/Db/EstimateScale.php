<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

/**
 * The fixed set of card-estimation scales a board may choose from. Estimates
 * are a plain sizing attribute (field + scale + display) - deliberately NO
 * burndown/velocity/reporting is derived from them (charter non-goal).
 *
 * A card's estimate is stored as the raw token from its board's scale, so a
 * single nullable string column carries both numeric (fibonacci/linear/hours)
 * and textual (t-shirt) scales. Validation constrains a card's value to its
 * board's scale; an off-scale value is rejected.
 */
final class EstimateScale {
	public const NONE = 'none';

	/**
	 * scale key => the allowed estimate tokens (order = display order).
	 * `none` means estimation is disabled for the board (no value may be set).
	 *
	 * @var array<string, string[]>
	 */
	public const SCALES = [
		self::NONE => [],
		'fibonacci' => ['1', '2', '3', '5', '8', '13', '21'],
		'tshirt' => ['XS', 'S', 'M', 'L', 'XL'],
		'linear' => ['1', '2', '3', '4', '5'],
		'hours' => ['1', '2', '4', '8', '16', '24', '40'],
	];

	/** True if $scale is one of the known scale keys. */
	public static function isValidScale(string $scale): bool {
		return array_key_exists($scale, self::SCALES);
	}

	/**
	 * True if $value is an allowed token of $scale. The `none` scale allows no
	 * value at all.
	 */
	public static function allows(string $scale, string $value): bool {
		return in_array($value, self::SCALES[$scale] ?? [], true);
	}
}

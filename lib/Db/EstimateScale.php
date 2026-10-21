<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Db;

use OCA\Kanso\Service\InvalidInputException;

/**
 * The card-estimation scales a board may choose from. A card's estimate is
 * stored as the raw token of its board's scale; every token also has a numeric
 * value, which is what sums, sorting and project points use.
 *
 * Preset scales are fixed token lists. `number` accepts any non-negative
 * number, and `custom` uses the board's own labels and weights
 * (`kanso_boards.estimate_custom`).
 */
final class EstimateScale {
	public const NONE = 'none';
	public const NUMBER = 'number';
	public const CUSTOM = 'custom';

	public const MAX_TOKEN_LENGTH = 24;
	public const MAX_CUSTOM_TOKENS = 20;
	private const MAX_NUMBER = 100000;

	/**
	 * Preset scale key => token => value (order = display order). Numeric
	 * tokens become int keys, so compare labels as strings.
	 *
	 * @var array<string, array<array-key, float>>
	 */
	public const PRESETS = [
		'fibonacci' => ['1' => 1.0, '2' => 2.0, '3' => 3.0, '5' => 5.0, '8' => 8.0, '13' => 13.0, '21' => 21.0],
		'fibonacci_mod' => ['0' => 0.0, '0.5' => 0.5, '1' => 1.0, '2' => 2.0, '3' => 3.0, '5' => 5.0, '8' => 8.0, '13' => 13.0, '20' => 20.0, '40' => 40.0, '100' => 100.0],
		'powers2' => ['1' => 1.0, '2' => 2.0, '4' => 4.0, '8' => 8.0, '16' => 16.0, '32' => 32.0],
		'tshirt' => ['XS' => 1.0, 'S' => 2.0, 'M' => 3.0, 'L' => 5.0, 'XL' => 8.0],
		'linear' => ['1' => 1.0, '2' => 2.0, '3' => 3.0, '4' => 4.0, '5' => 5.0],
		'roman' => ['I' => 1.0, 'II' => 2.0, 'III' => 3.0, 'IV' => 4.0, 'V' => 5.0, 'VI' => 6.0, 'VII' => 7.0, 'VIII' => 8.0, 'IX' => 9.0, 'X' => 10.0],
		'hours' => ['1' => 1.0, '2' => 2.0, '4' => 4.0, '8' => 8.0, '16' => 16.0, '24' => 24.0, '40' => 40.0],
		'days' => ['0.5' => 0.5, '1' => 1.0, '2' => 2.0, '3' => 3.0, '5' => 5.0, '10' => 10.0],
	];

	/** Scales measured in time rather than relative size. */
	public const TIME_SCALES = ['hours', 'days'];

	public static function isValidScale(string $scale): bool {
		return $scale === self::NONE || $scale === self::NUMBER || $scale === self::CUSTOM || isset(self::PRESETS[$scale]);
	}

	/**
	 * The scale's tokens with their values, in display order. Empty for `none`
	 * and for the free-form `number` scale.
	 *
	 * @param list<array{label: string, value: float}> $custom
	 * @return array<array-key, float>
	 */
	public static function tokens(string $scale, array $custom = []): array {
		if ($scale === self::CUSTOM) {
			$tokens = [];
			foreach ($custom as $entry) {
				$tokens[$entry['label']] = $entry['value'];
			}
			return $tokens;
		}
		return self::PRESETS[$scale] ?? [];
	}

	/**
	 * The numeric value of $token on $scale, or null when the token is not on it.
	 *
	 * @param list<array{label: string, value: float}> $custom
	 */
	public static function value(string $scale, ?string $token, array $custom = []): ?float {
		if ($token === null || $token === '') {
			return null;
		}
		if ($scale === self::NUMBER) {
			return self::parseNumber($token);
		}
		$tokens = self::tokens($scale, $custom);
		// Preset numeric labels become int keys in PHP arrays, so look up by string.
		foreach ($tokens as $label => $value) {
			if ((string)$label === $token) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * @param list<array{label: string, value: float}> $custom
	 */
	public static function allows(string $scale, string $value, array $custom = []): bool {
		return self::value($scale, $value, $custom) !== null;
	}

	/**
	 * The stored form of a free-form number estimate ("3.50" → "3.5"), or null
	 * when $token isn't a non-negative number with at most two decimals.
	 */
	public static function normalizeNumber(string $token): ?string {
		$value = self::parseNumber($token);
		if ($value === null) {
			return null;
		}
		return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
	}

	/**
	 * Decodes the stored custom scale; anything malformed reads as empty.
	 *
	 * @return list<array{label: string, value: float}>
	 */
	public static function decodeCustom(?string $stored): array {
		if ($stored === null || $stored === '') {
			return [];
		}
		$decoded = json_decode($stored, true);
		if (!is_array($decoded)) {
			return [];
		}
		$entries = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) && is_string($entry['label'] ?? null) && is_numeric($entry['value'] ?? null)) {
				$entries[] = ['label' => $entry['label'], 'value' => (float)$entry['value']];
			}
		}
		return $entries;
	}

	/**
	 * Validates a custom scale from the API and returns its storage form.
	 *
	 * @param array<mixed> $entries list of {label, value}
	 * @throws InvalidInputException
	 */
	public static function encodeCustom(array $entries): string {
		if ($entries === [] || count($entries) > self::MAX_CUSTOM_TOKENS) {
			throw new InvalidInputException('A custom estimate scale needs 1 to ' . self::MAX_CUSTOM_TOKENS . ' labels');
		}
		$clean = [];
		$seen = [];
		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				throw new InvalidInputException('Each custom estimate needs a label and a value');
			}
			$label = trim((string)($entry['label'] ?? ''));
			$value = $entry['value'] ?? null;
			if ($label === '' || mb_strlen($label) > self::MAX_TOKEN_LENGTH) {
				throw new InvalidInputException('Custom estimate labels must be 1 to ' . self::MAX_TOKEN_LENGTH . ' characters');
			}
			if (isset($seen[mb_strtolower($label)])) {
				throw new InvalidInputException('Custom estimate labels must be unique');
			}
			if (!is_numeric($value) || (float)$value < 0 || (float)$value > self::MAX_NUMBER) {
				throw new InvalidInputException('Custom estimate values must be numbers from 0 to ' . self::MAX_NUMBER);
			}
			$seen[mb_strtolower($label)] = true;
			$clean[] = ['label' => $label, 'value' => round((float)$value, 2)];
		}
		return json_encode($clean, JSON_THROW_ON_ERROR);
	}

	private static function parseNumber(string $token): ?float {
		if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $token) !== 1) {
			return null;
		}
		$value = (float)$token;
		return $value <= self::MAX_NUMBER ? $value : null;
	}
}

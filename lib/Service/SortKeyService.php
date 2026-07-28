<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Service;

use OverflowException;

/**
 * Stateless generator of fractional (lexorank-style) sort keys.
 *
 * Keys are non-empty base-36 strings over the alphabet 0-9 A-Z. The alphabet
 * is deliberately single-case: MySQL's default collations
 * (utf8mb4_general_ci / utf8mb4_0900_ai_ci) compare case-insensitively, which
 * would silently break a mixed-case (base-62) ordering - with digits and one
 * case only, byte comparison (strcmp / SQL ORDER BY) yields the same order
 * under every collation Nextcloud supports. Moving an item is a single-row
 * UPDATE of its key - no renumbering of siblings, ever.
 *
 * Algorithm choices:
 * - between(): digit-by-digit midpoint. When the two keys are adjacent at
 *   their current length (no midpoint digit exists), the result is extended by
 *   at most one character, so repeated bisection between the same neighbours
 *   grows key length only logarithmically.
 * - after(): increment with carry-truncation - bump the rightmost digit that
 *   is below 'Z' and drop everything after it; if the key is all 'Z',
 *   append the mid digit 'I'. Sequential appends therefore grow in VALUE,
 *   not length (~26 appends per extra character).
 * - before(): the symmetric decrement toward '0' - decrement the rightmost
 *   digit that is >= '2' and truncate; if the key contains only '0'/'1'
 *   digits, replace the final '1' with '0Z'.
 *
 * Invariant: returned keys never end with the minimum digit '0', because such
 * a key would block any future before()-insertion at that position.
 *
 * Overflow: the backing DB column is a varchar(64). Whenever a computed key
 * would exceed {@see SortKeyService::MAX_KEY_LENGTH} characters an
 * \OverflowException is thrown; callers must treat this as "the affected list
 * needs a rebalance" (handled at the API layer, not here).
 */
class SortKeyService {
	public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	public const BASE = 36;

	/** Maximum key length supported by the kanso_cards.sort_key column. */
	public const MAX_KEY_LENGTH = 64;

	/** Middle digit of the alphabet, used when extending an all-'Z' key. */
	private const MID_DIGIT = 'I';

	/**
	 * First key for an empty list: the middle of the one-character key space,
	 * leaving room for insertions in both directions.
	 */
	public function initial(): string {
		return 'I';
	}

	/**
	 * Returns a key k with $a < k < $b (byte comparison).
	 *
	 * The result is at most one character longer than the longer input.
	 *
	 * @throws InvalidInputException if $a >= $b or either key is malformed
	 * @throws OverflowException if the result would exceed MAX_KEY_LENGTH
	 */
	public function between(string $a, string $b): string {
		$this->assertValidKey($a);
		$this->assertValidKey($b);
		if (strcmp($a, $b) >= 0) {
			throw new InvalidInputException(
				'between() requires a < b, got "' . $a . '" >= "' . $b . '"'
			);
		}
		return $this->guardLength($this->midpoint($a, $b));
	}

	/**
	 * Returns a key k > $a - tail (append) insertion.
	 *
	 * Increment with carry-truncation keeps appended keys short: the rightmost
	 * digit below 'Z' is bumped by one and the remainder dropped, so N
	 * sequential appends need only O(log N) characters.
	 *
	 * @throws InvalidInputException if $a is malformed
	 * @throws OverflowException if the result would exceed MAX_KEY_LENGTH
	 */
	public function after(string $a): string {
		$this->assertValidKey($a);
		for ($i = strlen($a) - 1; $i >= 0; $i--) {
			$value = $this->digitValue($a[$i]);
			if ($value < self::BASE - 1) {
				// Result never ends in '0': $value + 1 >= 1.
				return $this->guardLength(substr($a, 0, $i) . self::ALPHABET[$value + 1]);
			}
		}
		// All digits are 'Z' - extend into the middle of the next level.
		return $this->guardLength($a . self::MID_DIGIT);
	}

	/**
	 * Returns a key k < $b - head insertion.
	 *
	 * Symmetric to after(): the rightmost digit that is at least '2' is
	 * decremented and the remainder truncated (decrementing '1' to '0' would
	 * create a forbidden trailing '0'). If $b contains only '0'/'1' digits,
	 * its final '1' becomes '0Z'.
	 *
	 * @throws InvalidInputException if $b is malformed
	 * @throws OverflowException if the result would exceed MAX_KEY_LENGTH
	 */
	public function before(string $b): string {
		$this->assertValidKey($b);
		for ($i = strlen($b) - 1; $i >= 0; $i--) {
			$value = $this->digitValue($b[$i]);
			if ($value >= 2) {
				return $this->guardLength(substr($b, 0, $i) . self::ALPHABET[$value - 1]);
			}
		}
		// Only '0'/'1' digits left; a valid key always ends in '1' here.
		return $this->guardLength(substr($b, 0, -1) . '0' . self::ALPHABET[self::BASE - 1]);
	}

	/**
	 * Digit-by-digit midpoint of $a and $b, where $b === '' acts as positive
	 * infinity and $a === '' as the (exclusive) minimum. Preconditions: $a is
	 * lexicographically smaller than $b (or $b is ''), neither has a trailing
	 * '0'. The result is strictly between the two and never ends in '0'.
	 */
	private function midpoint(string $a, string $b): string {
		if ($b !== '') {
			// Consume the common prefix ($a is padded with '0' past its end).
			$n = 0;
			$bLength = strlen($b);
			while ($n < $bLength && ($a[$n] ?? '0') === $b[$n]) {
				$n++;
			}
			if ($n > 0) {
				return substr($b, 0, $n) . $this->midpoint(substr($a, $n), substr($b, $n));
			}
		}
		// The first digits differ (or a bound ran out).
		$digitA = $a === '' ? 0 : $this->digitValue($a[0]);
		$digitB = $b === '' ? self::BASE : $this->digitValue($b[0]);
		if ($digitB - $digitA > 1) {
			// Round half up; strictly between since the gap is at least 2.
			return self::ALPHABET[intdiv($digitA + $digitB + 1, 2)];
		}
		// Consecutive first digits: no midpoint exists at this length.
		if (strlen($b) > 1) {
			// $b's first digit alone sorts after $a and before $b.
			return $b[0];
		}
		// Keep $a's first digit and bisect its remainder against infinity.
		if ($a === '') {
			return '0' . $this->midpoint('', '');
		}
		return $a[0] . $this->midpoint(substr($a, 1), '');
	}

	/**
	 * @throws InvalidInputException
	 */
	private function assertValidKey(string $key): void {
		if ($key === '' || preg_match('/^[0-9A-Z]+$/', $key) !== 1) {
			throw new InvalidInputException('Invalid sort key "' . $key . '"');
		}
		if ($key[strlen($key) - 1] === '0') {
			throw new InvalidInputException(
				'Invalid sort key "' . $key . '": keys must not end with "0"'
			);
		}
	}

	/**
	 * @throws OverflowException if the key exceeds MAX_KEY_LENGTH - the caller
	 *                           must rebalance the list
	 */
	private function guardLength(string $key): string {
		if (strlen($key) > self::MAX_KEY_LENGTH) {
			throw new OverflowException(
				'Sort key would exceed ' . self::MAX_KEY_LENGTH . ' characters, rebalance needed'
			);
		}
		return $key;
	}

	/**
	 * @throws InvalidInputException
	 */
	private function digitValue(string $digit): int {
		$value = strpos(self::ALPHABET, $digit);
		if ($value === false) {
			throw new InvalidInputException('Invalid sort key digit "' . $digit . '"');
		}
		return $value;
	}
}

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Port of lib/Service/SortKeyService.php - keep in sync.
 *
 * Stateless generator of fractional (lexorank-style) sort keys.
 * Base-36 alphabet: '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'
 * Keys never end with '0'. Max length 64 chars (OverflowError thrown if exceeded).
 */

const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'
const BASE = 36
const MAX_KEY_LENGTH = 64
const MID_DIGIT = 'I'

function digitValue(ch) {
	const v = ALPHABET.indexOf(ch)
	if (v === -1) throw new Error(`Invalid sort key digit "${ch}"`)
	return v
}

function assertValidKey(key) {
	if (!key || !/^[0-9A-Z]+$/.test(key)) throw new Error(`Invalid sort key "${key}"`)
	if (key[key.length - 1] === '0') throw new Error(`Invalid sort key "${key}": must not end with "0"`)
}

function guardLength(key) {
	if (key.length > MAX_KEY_LENGTH)
		throw new RangeError(`Sort key would exceed ${MAX_KEY_LENGTH} characters, rebalance needed`)
	return key
}

/**
 * Digit-by-digit midpoint. b==='' means +∞, a==='' means exclusive minimum.
 */
function midpoint(a, b) {
	if (b !== '') {
		let n = 0
		const bLen = b.length
		while (n < bLen && (a[n] ?? '0') === b[n]) n++
		if (n > 0) return b.slice(0, n) + midpoint(a.slice(n), b.slice(n))
	}
	const digitA = a === '' ? 0 : digitValue(a[0])
	const digitB = b === '' ? BASE : digitValue(b[0])
	if (digitB - digitA > 1) {
		return ALPHABET[Math.floor((digitA + digitB + 1) / 2)]
	}
	// Consecutive first digits
	if (b.length > 1) return b[0]
	if (a === '') return '0' + midpoint('', '')
	return a[0] + midpoint(a.slice(1), '')
}

/** First key for an empty list. */
export function initial() {
	return MID_DIGIT
}

/**
 * Returns k where a < k < b (codepoint/byte comparison).
 * @throws {Error} if a >= b or malformed
 * @throws {RangeError} if result > 64 chars
 */
export function between(a, b) {
	assertValidKey(a)
	assertValidKey(b)
	if (a >= b) throw new Error(`between() requires a < b, got "${a}" >= "${b}"`)
	return guardLength(midpoint(a, b))
}

/**
 * Returns k > a (tail/append insertion).
 * @throws {RangeError} if result > 64 chars
 */
export function after(a) {
	assertValidKey(a)
	for (let i = a.length - 1; i >= 0; i--) {
		const v = digitValue(a[i])
		if (v < BASE - 1) return guardLength(a.slice(0, i) + ALPHABET[v + 1])
	}
	return guardLength(a + MID_DIGIT)
}

/**
 * Returns k < b (head/prepend insertion).
 * @throws {RangeError} if result > 64 chars
 */
export function before(b) {
	assertValidKey(b)
	for (let i = b.length - 1; i >= 0; i--) {
		const v = digitValue(b[i])
		if (v >= 2) return guardLength(b.slice(0, i) + ALPHABET[v - 1])
	}
	// Only 0/1 digits; replace final '1' with '0Z'
	return guardLength(b.slice(0, -1) + '0' + ALPHABET[BASE - 1])
}

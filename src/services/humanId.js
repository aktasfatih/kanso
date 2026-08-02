// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * A card's human-readable reference is its board's prefix joined to its
 * board-scoped sequence number, e.g. "KAN-123". Ordering is unaffected (that
 * stays the fractional sort key) - this is a DISPLAY/reference id only.
 *
 * The number (`boardSeq`) rides the card payload; the prefix rides the board
 * payload (both already in the TanStack Query cache on the board view and in
 * the card modal). Compose them at the display boundary so neither side stores
 * a denormalized copy.
 */

/** The default prefix, mirroring the server's BoardPrefix::DEFAULT. */
export const DEFAULT_PREFIX = 'KAN'

/**
 * Compose a card's human id from a board prefix and the card's board sequence
 * number. Returns null when the card has no assigned number yet (a pre-migration
 * row), so callers can hide the badge rather than render a broken "KAN-".
 *
 * @param {string|null|undefined} prefix the board prefix
 * @param {number|null|undefined} boardSeq the card's per-board number
 * @return {string|null} e.g. "KAN-123", or null when no number is assigned
 */
export function humanId(prefix, boardSeq) {
	if (boardSeq === null || boardSeq === undefined || Number(boardSeq) <= 0) {
		return null
	}
	const p = (prefix || DEFAULT_PREFIX).toString()
	return `${p}-${boardSeq}`
}

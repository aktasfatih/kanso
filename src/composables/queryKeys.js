// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Returns the TanStack Query key for a board query.
 *
 * This is extracted into its own module (no other composable imports) so that
 * useBoard.js and useCardMove.js can both import it without creating a circular
 * dependency between each other.
 *
 * The id is coerced to a String so every producer and consumer of this key
 * agrees on its type. The board query is registered from the STRING route param
 * (`['board', '14']`), but several optimistic patches derive the id from a
 * NUMERIC `card.boardId` API field. Without coercion those would resolve to
 * `['board', 14]` — a different cache entry — and `setQueryData` would silently
 * no-op, dropping optimistic board-tile updates until the next poll.
 *
 * Accepts a ref, a getter function, or a plain primitive.
 *
 * @param {number|string|import('vue').Ref|Function} id
 * @returns {[string, string]}
 */
export function boardQueryKey(id) {
	let value = id
	if (typeof value === 'function') {
		value = value()
	} else if (value !== null && typeof value === 'object' && value.value !== undefined) {
		value = value.value
	}
	return ['board', String(value)]
}

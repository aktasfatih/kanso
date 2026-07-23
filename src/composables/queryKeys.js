// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Returns the TanStack Query key for a board query.
 *
 * This is extracted into its own module (no other composable imports) so that
 * useBoard.js and useCardMove.js can both import it without creating a circular
 * dependency between each other.
 *
 * @param {number|string|import('vue').Ref} id
 * @returns {[string, string|number]}
 */
export function boardQueryKey(id) {
	const value = typeof id === 'object' && id !== null && id.value !== undefined ? id.value : id
	return ['board', value]
}

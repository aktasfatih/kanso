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

/**
 * Key family for the cross-board "My Work" feeds (My Tasks / My Reviews /
 * Inbox). These queries live outside the per-board cache, so board-scoped
 * invalidation and delta sync never touch them (#3766).
 *
 * @type {Array<[string]>}
 */
export const MY_WORK_QUERY_KEYS = [['my-cards'], ['my-reviews'], ['inbox']]

/**
 * Visible-tab polling cadence for the My Work feeds (#3768).
 *
 * The feeds are cross-board, so no board delta poll ever covers them - while
 * the user sits on My Tasks / My Reviews / Inbox, this interval IS the delta
 * mechanism for other users' changes (their own mutations and navigation are
 * already covered by invalidateMyWork + refetchOnMount 'always', #3766).
 *
 * 60s matches the board query's full-refetch safety net, not the 5s/30s delta
 * poll: these queries are full list reads (small, per-user, server-filtered),
 * so they align with the board's full-read cadence. TanStack's default
 * refetchIntervalInBackground=false skips interval ticks while the tab is
 * hidden/unfocused, so background tabs generate no traffic.
 *
 * @type {number}
 */
export const MY_WORK_POLL_INTERVAL = 60_000

/**
 * Invalidate every cross-board My Work feed (#3766).
 *
 * Call this from the settle phase of any mutation that can change membership
 * of a My Work set: card assign/unassign, review request/withdraw/verdict,
 * watch/unwatch, card done/archive/delete/restore, move-to-board, and bulk
 * apply. The nav badges (useMyWorkBadges) keep these three queries mounted for
 * the app's lifetime, so invalidating here refetches them immediately and the
 * feeds are already fresh when the user navigates to a My Work page.
 *
 * Never call this from onMutate - the settle phase only - so it can never
 * interfere with optimistic patches or their rollback. The feeds are small,
 * per-user, server-filtered lists (three cheap GETs), so refetching all three
 * on a membership-changing user action is deliberate and proportionate.
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 */
export function invalidateMyWork(queryClient) {
	for (const queryKey of MY_WORK_QUERY_KEYS) {
		queryClient.invalidateQueries({ queryKey })
	}
}

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
 * Key for the cross-board card feed every saved View renders over (#3815).
 *
 * Single-sourced here rather than inlined in useViewCards.js so the feed can
 * never again become a cache island that no mutation knows how to reach - the
 * exact bug this key's first year shipped with.
 *
 * Deliberately NOT a member of MY_WORK_QUERY_KEYS - see
 * invalidateCrossBoardFeeds for why the two lists must stay separate.
 *
 * This is the key PREFIX: the feed is server-sorted, so useViewCards appends the
 * active sort mode + direction (one cache entry per ordering). Invalidating the
 * prefix reaches every one of them - TanStack matches keys by prefix.
 *
 * @type {[string]}
 */
export const VIEW_CARDS_QUERY_KEY = ['view-cards']

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

/**
 * Burst window for the View feed invalidation (#9981).
 *
 * The sibling of main.js's MY_WORK_SIGNAL_THROTTLE, and the same policy read
 * from the other end: the View feed is the heaviest query in the app, so
 * neither the realtime cadence nor a mutation burst may drive it once per tick.
 * main.js throttles the REALTIME funnel (30s, and to the narrow invalidator);
 * this throttles the MUTATION funnel.
 *
 * Much shorter than the realtime one because the trigger is different in kind.
 * A push event is someone else's change, invisible until it lands, so 30s of
 * staleness is a fair trade. Here the user is typing in an overlay ON the View,
 * so the feed must repaint at human speed - hence leading edge + a short
 * trailing window, not a long throttle.
 *
 * @type {number}
 */
export const VIEW_FEED_INVALIDATE_THROTTLE = 400

// Burst state for invalidateViewFeed. Module-scoped, like main.js's
// lastMyWorkSignal: there is exactly one View feed and one QueryClient per app.
let viewFeedLastInvalidate = 0
let viewFeedTrailingTimer = null
let viewFeedPendingClient = null

/**
 * Invalidate the View feed at most twice per burst: immediately on the first
 * call, then once more when the burst settles (#9981).
 *
 * Why this is needed at all: ticking a checklist item, toggling a label and
 * setting a priority all settle through invalidateCrossBoardFeeds, and roughly
 * twenty call sites do. When the card overlay was opened FROM a View, ViewPage
 * stays mounted behind it, so ['view-cards'] is an ACTIVE query - the only kind
 * invalidateQueries actually refetches. Five quick edits therefore meant five
 * full cross-board reads. And TanStack does not absorb them: invalidateQueries
 * defaults to cancelRefetch:true, but getViewCards is a plain axios GET with no
 * AbortSignal, so a "cancelled" refetch's request still runs to completion
 * server-side. N ticks really were N round trips.
 *
 * LEADING EDGE FIRES IMMEDIATELY - do not turn this into a plain debounce. The
 * first edit of a burst is the one the user is watching for, and the View tile
 * behind the overlay has to repaint on it (tests/e2e/view-checklist-live.spec.js
 * asserts exactly that). The trailing edge then folds the rest of the burst into
 * a single catch-up refetch, so the feed still ends up consistent with the last
 * edit.
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 */
function invalidateViewFeed(queryClient) {
	const elapsed = Date.now() - viewFeedLastInvalidate
	if (viewFeedTrailingTimer === null && elapsed >= VIEW_FEED_INVALIDATE_THROTTLE) {
		viewFeedLastInvalidate = Date.now()
		queryClient.invalidateQueries({ queryKey: VIEW_CARDS_QUERY_KEY })
		return
	}
	// Inside the window: remember the client and make sure exactly one trailing
	// refetch is scheduled. Later calls in the same burst are absorbed by it.
	viewFeedPendingClient = queryClient
	if (viewFeedTrailingTimer !== null) {
		return
	}
	viewFeedTrailingTimer = setTimeout(() => {
		viewFeedTrailingTimer = null
		viewFeedLastInvalidate = Date.now()
		const client = viewFeedPendingClient
		viewFeedPendingClient = null
		client.invalidateQueries({ queryKey: VIEW_CARDS_QUERY_KEY })
	}, VIEW_FEED_INVALIDATE_THROTTLE - elapsed)
	// Node's timer object only - a browser setTimeout returns a number.
	viewFeedTrailingTimer.unref?.()
}

/**
 * Invalidate EVERY cross-board feed: the three My Work feeds plus the View feed.
 *
 * This is the one to call from the settle phase of a card mutation. A View is a
 * cross-board feed exactly like My Tasks - board-scoped invalidation and delta
 * sync never reach it - and it is worse off than they are: the card detail opens
 * as an in-place overlay ON the View (ViewPage.vue), so editing never blurs the
 * window and refetchOnWindowFocus never fires. Without this the tile behind the
 * overlay keeps the old values until the 60s interval ticks.
 *
 * ── Why this is NOT just `MY_WORK_QUERY_KEYS + ['view-cards']` ──────────────
 * main.js drives invalidateMyWork from the REALTIME path: every notify_push
 * event and every applied board delta, throttled to 30s. That is right for the
 * My Work feeds (small, per-user, server-filtered list reads) and wrong for the
 * View feed, which is the heaviest query in the app - enriched summaries across
 * every readable board, up to the server cap. Folding view-cards into the
 * My Work list would put that query on a 30s push-driven cadence, i.e. DOUBLE
 * its own 60s interval, purely as a side effect of someone else touching any
 * board. And it would bite precisely where it hurts: useViewCards is mounted
 * only by ViewPage, so the key is an ACTIVE query - the only kind
 * invalidateQueries refetches - exactly when the user is sitting on a View.
 *
 * So: mutations (a user action, bounded, already paying for a round trip) call
 * this; the realtime funnel keeps calling the narrow invalidateMyWork. The two
 * functions are separate on purpose - do not merge them.
 *
 * Same rule as invalidateMyWork: settle phase only, never onMutate, so it can
 * never race an optimistic patch or its rollback. And no optimistic patch of
 * the feed itself - once filtering moves server-side, a client-side field patch
 * cannot know whether the card still belongs in the filtered set.
 *
 * The View half is burst-collapsed (see invalidateViewFeed); the My Work half is
 * NOT, and must stay immediate - three cheap per-user GETs, as the note on
 * invalidateMyWork says. Only the heavy query needs the guard.
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 */
export function invalidateCrossBoardFeeds(queryClient) {
	invalidateMyWork(queryClient)
	invalidateViewFeed(queryClient)
}

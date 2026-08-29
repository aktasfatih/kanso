// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import '@nextcloud/dialogs/style.css'
import './styles/kanso-page-header.css'
import { createApp } from 'vue'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import App from './App.vue'
import { router } from './router/index.js'
import { initRealtime } from './services/realtime.js'
import { isBoardMovePending } from './composables/useCardMove.js'
import { syncBoardDelta, onBoardChangesApplied } from './composables/useBoardDelta.js'
import { invalidateMyWork } from './composables/queryKeys.js'

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			staleTime: 30_000,
			retry: 1,
		},
	},
})

// Fragment-free deep link handoff (#3744): the server route /card/{id}
// validated access and left the target in the `openCard` initial state
// (a base64-JSON <input> NC renders into the page). The SPA is hash-routed,
// so we translate the server target into the card-modal hash route before
// the first paint. Hand-rolled reader (the element is trivial) - no extra
// @nextcloud/initial-state dependency for one key.
function readOpenCardState() {
	const el = document.getElementById('initial-state-kanso-openCard')
	if (!el) {
		return null
	}
	try {
		const state = JSON.parse(atob(el.value))
		const boardId = Number(state?.boardId)
		const cardId = Number(state?.cardId)
		// Positive integers only - a malformed blob must not produce a junk
		// route like /board/undefined/card/NaN.
		return Number.isInteger(boardId) && boardId > 0 && Number.isInteger(cardId) && cardId > 0
			? { boardId, cardId }
			: null
	} catch {
		return null
	}
}

createApp(App)
	.use(router)
	.use(VueQueryPlugin, { queryClient })
	.mount(document.getElementById('kanso'))

// The replace must wait for the router's INITIAL navigation (started by the
// mount above): a replace issued before it is clobbered when the initial
// '/' navigation settles.
const openCard = readOpenCardState()
if (openCard) {
	// Scroll-to-comment deep link (#3870): a reminder link carries a
	// `#comment-<id>` fragment after the fragment-free server route. The SPA is
	// hash-routed, so the raw fragment would be clobbered by the replace below;
	// capture it FIRST and carry it into the target route as `?comment=<id>` so
	// CardDetail can scroll to + highlight that comment once the thread loads.
	const commentMatch = /(?:^|#)comment-(\d+)\s*$/.exec(window.location.hash || '')
	const target = {
		path: `/board/${openCard.boardId}/card/${openCard.cardId}`,
		...(commentMatch ? { query: { comment: commentMatch[1] } } : {}),
	}
	router.isReady().then(() => {
		router.replace(target)
	})
}

// My Work live-updates, fast path (#3768). The PRIMARY freshness mechanism for
// the cross-board feeds (My Tasks / My Reviews / Inbox) is their own 60s
// visible-tab refetchInterval (MY_WORK_POLL_INTERVAL) - it works everywhere,
// including a user parked on a My Work page with no board mounted. This funnel
// is the free upgrade on top: a push event ("a board you participate in
// changed") or an applied board delta is exactly a "my feeds may have changed"
// signal, so we invalidate the always-mounted feed queries and the change shows
// up near-instantly instead of at the next 60s tick.
//
// Guards (both mirror the delta machinery's own conventions):
//  - throttle: board deltas can arrive every few seconds on a busy board; the
//    feeds are refreshed at most once per 30s (global staleTime) - the 60s
//    interval is the backstop for anything a throttled window skipped.
//  - isMutating: never race an optimistic my-work patch (review verdict) with
//    a refetch; the mutation's own settle-phase invalidateCrossBoardFeeds
//    covers it.
//  - hidden tab: push events still arrive there, and invalidateQueries
//    refetches active queries regardless of focus (the feeds' own interval is
//    focus-gated by TanStack) - skip, and let refetchOnWindowFocus +
//    refetchOnMount 'always' catch the tab up when it returns.
const MY_WORK_SIGNAL_THROTTLE = 30_000
let lastMyWorkSignal = 0
function invalidateMyWorkThrottled() {
	if (document.visibilityState === 'hidden') {
		return
	}
	const now = Date.now()
	if (now - lastMyWorkSignal < MY_WORK_SIGNAL_THROTTLE) {
		return
	}
	if (queryClient.isMutating() > 0) {
		return
	}
	lastMyWorkSignal = now
	// The NARROW variant, deliberately: this fires on every push for every board
	// the user participates in. The View feed (invalidateCrossBoardFeeds) is the
	// heaviest query in the app and must not ride a 30s push cadence - it keeps
	// its own 60s interval. See invalidateCrossBoardFeeds in queryKeys.js (#9859).
	// One policy, two ends: this throttle guards the REALTIME funnel, and
	// VIEW_FEED_INVALIDATE_THROTTLE in queryKeys.js guards the MUTATION funnel,
	// where a burst of overlay edits would otherwise refetch the feed per tick
	// (#9981).
	invalidateMyWork(queryClient)
}
onBoardChangesApplied(invalidateMyWorkThrottled)

// Realtime: a push event means someone changed the board - delta-sync it (#3675)
// instead of re-downloading the whole board. syncBoardDelta fetches only the
// changes since our cursor and PATCHES the cache (O(delta), not O(boardSize));
// on a resync signal or error it falls back to a full board invalidate, and it
// re-checks the move-pending guard internally so it never clobbers an optimistic
// move (the move queue's drain invalidate reconciles afterwards).
initRealtime((boardId) => {
	// The push body names a board, but the my-work feeds are cross-board and
	// need no cursor: refresh them even when the board itself was never opened
	// this session (syncBoardDelta would early-return without a cursor) (#3768).
	invalidateMyWorkThrottled()
	if (isBoardMovePending(boardId)) {
		return
	}
	syncBoardDelta(queryClient, boardId)
})

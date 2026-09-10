// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, onScopeDispose, ref, watch } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchBoard,
	updateBoard as apiUpdateBoard,
	createStack as apiCreateStack,
	updateStack as apiUpdateStack,
	deleteStack as apiDeleteStack,
	restoreStack as apiRestoreStack,
	createCard as apiCreateCard,
} from '../services/api.js'
import { pushActive } from '../services/realtime.js'
import { isBoardMovePending } from './useCardMove.js'
import { seedCursor, syncBoardDelta } from './useBoardDelta.js'
import { boardQueryKey } from './queryKeys.js'
// Re-export from the shared key module so existing callers of
// import { boardQueryKey } from './useBoard.js' continue to work.
export { boardQueryKey } from './queryKeys.js'

/**
 * Is this failure the server ANSWERING about access, or failing to answer at all?
 *
 * The whole of #10385 turns on that distinction. A 403/404 is an answer - you may
 * not have this board (or it is gone) - and it is terminal: the payload on screen
 * describes access the viewer no longer holds, so it must not go on being
 * rendered. A 500, a timeout, an aborted request, a dropped connection: those are
 * the server failing to answer, and the cached payload is the best thing the
 * client has. Blanking the board on THOSE would turn a flaky network into an
 * empty screen - a worse bug than the one this fixes, and it would undo both the
 * offline cache and #10299's 304 path, whose entire point is that a re-read which
 * does not produce a new board leaves the rendered one alone.
 *
 * A transport failure has no `response` at all, which is exactly why the status
 * is read off `error.response` rather than off any code the client invents.
 *
 * 403 on this GET can only be Kanso's own ACL answer. 404 is the looser of the
 * two - a reverse proxy, a disabled app or a mid-upgrade Nextcloud can produce
 * one that Kanso never saw - and it is classified with it anyway, because that is
 * already the answer BoardView gives such a response ("This board no longer
 * exists.", #3662) and a client cannot tell the difference from the body either.
 * The cost of being wrong is one re-read: the board comes back as soon as the
 * viewer navigates to it again.
 *
 * @param {unknown} error - a rejected fetchBoard error, or null
 * @return {boolean} true only for an authorisation/existence answer
 */
function isAccessAnswer(error) {
	const status = error?.response?.status
	return status === 403 || status === 404
}

export function useBoard(id) {
	const queryClient = useQueryClient()

	// The access answer that ended this board, if one has arrived (#10385).
	//
	// A failed re-read does NOT take the payload away: TanStack moves `status` to
	// 'error' but keeps `data` exactly as it was (query.js's error reducer), and
	// BoardView renders its stacks from `data` alone, so a board whose access was
	// revoked while it was open went on rendering underneath the error box - the
	// one place the user is told they no longer have access is a banner above a
	// board that is still showing them its cards.
	//
	// Sticky, because the answer has to outlive the query state it came from: the
	// cache entry is dropped below, and the observer then rebuilds an empty
	// `pending` query in its place, taking the error with it. Everything this
	// composable returns is derived from this ref, so BoardView, ArchivedView,
	// CardDetail and BoardSettingsModal inherit one answer instead of each
	// re-deriving it from raw query state.
	const accessError = ref(null)

	// Register and invalidate with the same coerced key every producer/consumer
	// uses, so optimistic board-tile patches (comment counts, checklist progress)
	// land on this exact cache entry instead of a numeric-keyed sibling.
	//
	// Kept as a computed (not a hoisted constant): BoardView is reused across
	// board switches (router-view has no :key), so `id` is a reactive ref/getter
	// that changes on navigation. Resolving it once at setup would freeze the key
	// to the first board and break refetch/invalidation after switching boards.
	const boardKey = computed(() => boardQueryKey(id))

	const query = useQuery({
		queryKey: boardKey,
		// Don't fetch until the id is a real value. On the full-page card route the
		// board id is only known once the card loads (it's derived from cardData),
		// so without this guard the first render fires GET /boards/undefined.
		// …and never again once the server has answered that this board is not
		// ours (#10385). Without this, the removeQueries below is undone by the
		// observer rebuilding the entry and fetching it straight back: a 403 loop
		// for as long as the tab stays open.
		enabled: computed(() => {
			const b = typeof id === 'object' ? id.value : id
			return b !== null && b !== undefined && b !== 'undefined'
				&& accessError.value === null
		}),
		queryFn: async () => {
			const boardId = typeof id === 'object' ? id.value : id
			// Conditional read (speed bet #4): hand fetchBoard the payload we
			// already hold so it can replay this board's ETag. On a 304 it gives
			// that same object back, and the re-read costs an ACL check and a
			// MAX(id) instead of the whole stacks + cards + labels assembly.
			// The reads this pays off on are the ones that re-fetch a board
			// nothing has changed: coming back to a board left for longer than
			// staleTime, a tab regaining focus, a delta resync, a mutation that
			// failed. Passing the cache entry in (rather than fetchBoard reaching
			// for it) is what keeps the "never overwrite a rendered board with an
			// empty 304 body" guarantee inside one function; see services/api.js.
			const previous = queryClient.getQueryData(boardKey.value)
			const data = await fetchBoard(boardId, previous)
			// Seed / re-seed the delta-sync cursor from the board payload's
			// latest change id, so the delta poll can advance from here (#3675).
			seedCursor(boardId, data.cursor)
			return data
		},
		// Belt-and-suspenders full refetch (charter's state pattern): a slow 60s
		// safety net that self-heals any missed delta and re-seeds the cursor.
		// The fast realtime channel is now the delta poll below, not this refetch,
		// so even without push we only fall back to a full board read once a
		// minute. Never fires mid-drag - a refetch would clobber optimistic patches.
		refetchInterval: () => {
			if (isBoardMovePending(id)) {
				return false
			}
			return 60_000
		},
	})

	// An access answer ends the board (#10385). The re-read restored in #10384 is
	// what produces it: revoke a viewer's access entirely and the next safety-net
	// read - or the board invalidate the delta poll does when /changes 403s, which
	// is why this lands within one poll rather than within a minute - is answered
	// 403. What that answer did NOT do was take the board off the screen, because
	// a failed refetch leaves `data` in place and the stacks render from `data`.
	//
	// Two things happen, and the second is the one that makes it stick:
	//  1. the answer is latched, so everything below reports it however the
	//     underlying query state moves afterwards;
	//  2. the cached payload is DROPPED, so nothing can render it again -
	//     including the offline snapshot, which is a dehydrate of this same cache
	//     on every change (services/offlineCache.js), so the removal un-persists
	//     it too. Leaving it would resurrect the board on the next navigation
	//     back to it, for as long as the snapshot lives.
	//
	// The delta cursor is deliberately NOT dropped here: syncBoardDelta's own
	// error path already drops it (useBoardDelta.js), so a copy in this file
	// would be a second guard on the same condition that no test could tell apart
	// from the first - the failure mode this file spends thirty lines on below.
	// A leftover cursor is harmless anyway: every successful board read re-seeds
	// it, so it can only ever be replaced, never trusted stale.
	watch(query.error, (error) => {
		if (accessError.value !== null || !isAccessAnswer(error)) {
			return
		}
		accessError.value = error
		queryClient.removeQueries({ queryKey: boardKey.value, exact: true })
	})

	// BoardView is reused across board switches (router-view has no :key), so the
	// latch has to be per board, not per component: leaving it set would make the
	// NEXT board the user opens inherit the previous one's 403. Clearing it on the
	// key change also means coming back to the revoked board re-asks the server
	// rather than trusting a latch - it just has nothing cached to render while it
	// waits for the answer, which is the point of the drop above. That navigation
	// is also the only way back if the board is shared with them AGAIN while they
	// sit on the error: the latch clears on a board change, not on a timer. Note
	// for anyone adding a manual "refresh" affordance - `refetch` bypasses
	// `enabled`, so it would fetch a payload the mask then hides; clear the latch
	// (navigate) rather than refetch under it.
	watch(boardKey, () => {
		accessError.value = null
	})

	// Delta poll (#3675): instead of re-downloading the whole board, fetch only
	// the changes since our cursor and PATCH the cache. Fast when push is absent
	// (5s), a slow secondary safety net when push covers realtime (30s, since the
	// push handler in main.js already delta-syncs on each mutation). Guarded to
	// never run mid-drag inside syncBoardDelta.
	//
	// A self-rescheduling timeout, not setInterval, on purpose (#10225):
	// pushActive() is false until the first push frame proves the socket is
	// really live, so the cadence has to be re-read on every tick. setInterval
	// captures its delay once at setup and would pin the board to whatever push
	// looked like the instant the view mounted - the flag flipping later would
	// change nothing.
	//
	// Hidden tabs don't poll (#10278). Every sibling feed gets this for free from
	// TanStack's refetchIntervalInBackground=false default (queryKeys.js,
	// useMyReviews.js, useInbox.js); this loop is hand-rolled, so it has to
	// implement the same policy itself - a board left open in a background tab
	// otherwise hits /changes forever, and that tick is NOT free: the endpoint
	// has no ETag/304 path (BoardController::changes), so every empty poll still
	// costs a request, an ACL check and a findSince.
	// Read as `visibilityState`, the same bit main.js:invalidateMyWorkThrottled and
	// TanStack's own focusManager test, so the whole app agrees on one definition
	// of "hidden". `typeof document` because the unit rig stubs `window` without a
	// DOM.
	const isHidden = () => typeof document !== 'undefined' && document.visibilityState === 'hidden'
	// The one condition this loop owns: a tab nobody is looking at does no work.
	//
	// Mid-drag suppression is deliberately NOT duplicated here. syncBoardDelta
	// refuses at its own entry, which covers this loop and the visibilitychange
	// handler below alike. A second copy lived here until #10292, and the pair was
	// worse than either alone: with both in place, deleting EITHER left the whole
	// realtime suite green, because the other still refused - so neither guard was
	// pinned by any test. Now the entry check is the one this loop relies on, and
	// pushLiveness.test.mjs ('the poll survives ticks it skips') reddens if it goes.
	//
	// Two further copies of the check exist and are NOT pinned by anything
	// (measured, not assumed): syncBoardDelta's post-fetch re-check, which covers a
	// move that starts while the delta is in flight and so is genuinely load-bearing,
	// and main.js's pre-check on the push path. Neither is touched here - they are
	// their own follow-up, not a licence to add a third copy back into this file.
	//
	// A board whose access has been answered away is the other condition this loop
	// owns, and it is not a duplicate of the paragraph above: when the poll is the
	// one that gets the 403 it drops its own cursor and then no-ops on `since ===
	// undefined`, but when the BOARD READ is the one that gets it - the 60s
	// safety net beating a 30s push-cadence tick to it - the cursor is still
	// seeded and this loop would go on requesting /changes for a board it may not
	// read, forever. That case is what this clause covers, and what
	// boardAccessRevoked.test.mjs pins it with.
	const shouldSync = () => !isHidden() && accessError.value === null
	let deltaTimer = null
	// The dispose below clears `deltaTimer`, but clearTimeout can only cancel a
	// timer that is still PENDING. A dispose landing after this callback entered its
	// `try` and before the `finally` would find nothing to cancel, and the finally
	// would then arm a fresh timer nothing holds a handle to: an immortal 5s
	// /changes loop for a board that no longer exists, one per occurrence. No such
	// path is known today (Vue defers unmount to its scheduler, and the only
	// synchronous work in the try is an invalidation), but CardDetail and
	// BoardSettingsModal each compose useBoard per open, so the blast radius if one
	// ever appears is per-interaction - and the latch is one line (#10292).
	let stopped = false
	const scheduleDelta = () => {
		deltaTimer = setTimeout(() => {
			// The re-arm is in a `finally` because this loop IS the poll: unlike
			// setInterval - which fires again regardless of what its callback did -
			// a single throw here would end the chain for the lifetime of the page
			// and leave only the 60s refetch. Same reason the skips above are
			// conditions and not an early return: an early `return` past this
			// finally would stop the poll for the rest of the session, silently,
			// for VISIBLE tabs too.
			try {
				if (shouldSync()) {
					syncBoardDelta(queryClient, id)
				}
			} finally {
				if (!stopped) {
					scheduleDelta()
				}
			}
		}, pushActive() ? 30_000 : 5_000)
	}
	scheduleDelta()

	// Catch the tab up on the way back in, for the window nothing else covers.
	// TanStack's focusManager listens to this same event, so a return already
	// triggers the query's own refetchOnWindowFocus - but only once the data is
	// stale, and staleTime is 30s globally (main.js). Hide for 10s and come back
	// and no refetch fires; without this handler the board would then wait out the
	// rest of the poll interval. This covers exactly that sub-staleTime gap, and it
	// covers it as a delta (O(changes)) rather than a full board read.
	//
	// visibilitychange fires on hide as well as show, hence the guard inside: only
	// the transition TO visible does work. And, like the loop above, this is per
	// useBoard instance - CardDetail's is alive on top of BoardView's while a card
	// modal is open - so a return costs one delta read per live consumer, all in
	// one event dispatch with the same cursor. That is the fan-out #10279 measures:
	// the loop already has it (staggered by mount time instead), it is not made
	// worse here, and it is not fixed here either.
	const onVisibilityChange = () => {
		if (shouldSync()) {
			syncBoardDelta(queryClient, id)
		}
	}
	if (typeof document !== 'undefined') {
		document.addEventListener('visibilitychange', onVisibilityChange)
	}
	onScopeDispose(() => {
		// Both are needed: the latch stops a re-arm from inside a tick already in
		// flight, clearTimeout stops the one sitting in the queue.
		stopped = true
		clearTimeout(deltaTimer)
		if (typeof document !== 'undefined') {
			document.removeEventListener('visibilitychange', onVisibilityChange)
		}
	})

	const createStack = useMutation({
		mutationFn: (data) => apiCreateStack(data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: boardKey.value }),
	})

	const updateStack = useMutation({
		mutationFn: ({ stackId, data }) => apiUpdateStack(stackId, data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: boardKey.value }),
	})

	const deleteStack = useMutation({
		mutationFn: (stackId) => apiDeleteStack(stackId),
		onSettled: () => queryClient.invalidateQueries({ queryKey: boardKey.value }),
	})

	const restoreStack = useMutation({
		mutationFn: (stackId) => apiRestoreStack(stackId),
		onSettled: () => queryClient.invalidateQueries({ queryKey: boardKey.value }),
	})

	const createCard = useMutation({
		mutationFn: (data) => apiCreateCard(data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: boardKey.value }),
	})

	const updateBoard = useMutation({
		mutationFn: (data) => apiUpdateBoard(typeof id === 'object' ? id.value : id, data),
		// Invalidate BOTH the single-board query and the boards list: a board-level
		// edit (rename, colour, …) must also refresh the app-navigation sidebar,
		// command palette and boards grid, which all read the ['boards'] list.
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: boardKey.value })
			queryClient.invalidateQueries({ queryKey: ['boards'] })
		},
	})

	// The query's own view of itself, normalised over the latch (#10385). These
	// deliberately override the query's refs in the spread below: a caller that
	// destructures `{ data, isError, error, isLoading }` - BoardView,
	// ArchivedView - gets the terminal answer without knowing this rule exists,
	// and a caller that only reads `data` - CardDetail, TrashView,
	// BoardSettingsModal - stops rendering a board it may no longer read.
	//
	// EVERY status field is derived, not just the ones read today, because the
	// entry is removed from the cache and the observer then rebuilds an empty
	// `pending` query in its place. Leave `status`/`isPending`/`isSuccess` raw and
	// the object contradicts itself - an error with `isPending: true` - and the
	// next consumer to write `if (isPending) renderSkeleton()` gets a skeleton
	// that never resolves, which is exactly the state this fix exists to remove.
	//
	// Only the terminal case is masked. A 500 or a dropped connection leaves
	// `data` exactly as it was, so the board on screen stays on screen and the
	// existing retryable error box appears over it - unchanged behaviour, and
	// preserving it is half of this fix, not an afterthought: the offline cache
	// and #10299's 304 path both rest on a re-read that produces no new board
	// leaving the rendered one alone.
	const isAccessRevoked = computed(() => accessError.value !== null)
	const data = computed(() => (isAccessRevoked.value ? undefined : query.data.value))
	const error = computed(() => accessError.value ?? query.error.value)
	const status = computed(() => (isAccessRevoked.value ? 'error' : query.status.value))
	const isError = computed(() => isAccessRevoked.value || query.isError.value)
	const isPending = computed(() => !isAccessRevoked.value && query.isPending.value)
	const isSuccess = computed(() => !isAccessRevoked.value && query.isSuccess.value)
	const isLoading = computed(() => !isAccessRevoked.value && query.isLoading.value)

	return {
		...query,
		data,
		error,
		status,
		isError,
		isPending,
		isSuccess,
		isLoading,
		createStack,
		updateStack,
		deleteStack,
		restoreStack,
		createCard,
		updateBoard,
	}
}

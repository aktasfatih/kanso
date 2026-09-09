// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, onScopeDispose } from 'vue'
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

export function useBoard(id) {
	const queryClient = useQueryClient()

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
		enabled: computed(() => {
			const b = typeof id === 'object' ? id.value : id
			return b !== null && b !== undefined && b !== 'undefined'
		}),
		queryFn: async () => {
			const boardId = typeof id === 'object' ? id.value : id
			const data = await fetchBoard(boardId)
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
	// The one condition that makes a tick worth doing. Mid-drag is skipped for
	// the reason syncBoardDelta documents: a patch would clobber the optimistic
	// placement.
	const shouldSync = () => !isHidden() && !isBoardMovePending(id)
	let deltaTimer = null
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
				scheduleDelta()
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

	return {
		...query,
		createStack,
		updateStack,
		deleteStack,
		restoreStack,
		createCard,
		updateBoard,
	}
}

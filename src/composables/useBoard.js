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
	const deltaTimer = setInterval(() => {
		if (isBoardMovePending(id)) {
			return
		}
		syncBoardDelta(queryClient, id)
	}, pushActive() ? 30_000 : 5_000)
	onScopeDispose(() => clearInterval(deltaTimer))

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
		onSettled: () => queryClient.invalidateQueries({ queryKey: boardKey.value }),
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

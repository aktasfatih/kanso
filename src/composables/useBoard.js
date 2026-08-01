// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from 'vue'
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
		queryFn: () => fetchBoard(typeof id === 'object' ? id.value : id),
		// Delta polling: the fallback realtime channel (5s, cheap thanks to
		// ETag/304) or a slow safety net when push covers realtime (60s).
		// Never fires mid-drag - a refetch would clobber optimistic patches.
		refetchInterval: () => {
			if (isBoardMovePending(id)) {
				return false
			}
			return pushActive() ? 60_000 : 5_000
		},
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

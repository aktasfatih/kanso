// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchBoards,
	createBoard as apiCreateBoard,
	updateBoard as apiUpdateBoard,
	deleteBoard as apiDeleteBoard,
	pinBoard as apiPinBoard,
	unpinBoard as apiUnpinBoard,
} from '../services/api.js'

export function useBoards() {
	const queryClient = useQueryClient()

	const query = useQuery({
		queryKey: ['boards'],
		queryFn: fetchBoards,
	})

	const createBoard = useMutation({
		mutationFn: (data) => apiCreateBoard(data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['boards'] }),
	})

	const updateBoard = useMutation({
		mutationFn: ({ id, data }) => apiUpdateBoard(id, data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['boards'] }),
	})

	const deleteBoard = useMutation({
		mutationFn: (id) => apiDeleteBoard(id),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['boards'] }),
	})

	// Per-user board pinning (#3632). Optimistic: the board's `pinned` flag on the
	// cached boards payload flips immediately, rolls back on error, and the server
	// truth reconciles on settle (project's db-first pattern).
	const setPinnedOptimistically = async (id, pinned) => {
		await queryClient.cancelQueries({ queryKey: ['boards'] })
		const previous = queryClient.getQueryData(['boards'])
		if (Array.isArray(previous)) {
			queryClient.setQueryData(['boards'], previous.map((b) =>
				Number(b.id) === Number(id) ? { ...b, pinned } : b,
			))
		}
		return { previous }
	}
	const rollbackPinned = (_err, _vars, context) => {
		if (context?.previous !== undefined) {
			queryClient.setQueryData(['boards'], context.previous)
		}
	}

	const pinBoard = useMutation({
		mutationFn: (id) => apiPinBoard(id),
		onMutate: (id) => setPinnedOptimistically(id, true),
		onError: rollbackPinned,
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['boards'] }),
	})

	const unpinBoard = useMutation({
		mutationFn: (id) => apiUnpinBoard(id),
		onMutate: (id) => setPinnedOptimistically(id, false),
		onError: rollbackPinned,
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['boards'] }),
	})

	// Toggle a board's pin from its current cached state.
	const togglePin = (board) => {
		if (board?.pinned) {
			unpinBoard.mutate(board.id)
		} else {
			pinBoard.mutate(board.id)
		}
	}

	return {
		...query,
		createBoard,
		updateBoard,
		deleteBoard,
		pinBoard,
		unpinBoard,
		togglePin,
	}
}

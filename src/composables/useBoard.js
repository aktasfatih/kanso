// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchBoard,
	createStack as apiCreateStack,
	updateStack as apiUpdateStack,
	deleteStack as apiDeleteStack,
	createCard as apiCreateCard,
} from '../services/api.js'

/**
 * The one place the board query key shape is defined. useCardMove patches
 * this cache entry directly — a divergent key there would silently turn
 * every optimistic move into a no-op against a different entry.
 */
export function boardQueryKey(id) {
	const value = typeof id === 'object' && id !== null && id.value !== undefined ? id.value : id
	return ['board', value]
}

export function useBoard(id) {
	const queryClient = useQueryClient()

	const query = useQuery({
		queryKey: ['board', id],
		queryFn: () => fetchBoard(typeof id === 'object' ? id.value : id),
	})

	const createStack = useMutation({
		mutationFn: (data) => apiCreateStack(data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['board', id] }),
	})

	const updateStack = useMutation({
		mutationFn: ({ stackId, data }) => apiUpdateStack(stackId, data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['board', id] }),
	})

	const deleteStack = useMutation({
		mutationFn: (stackId) => apiDeleteStack(stackId),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['board', id] }),
	})

	const createCard = useMutation({
		mutationFn: (data) => apiCreateCard(data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: ['board', id] }),
	})

	return {
		...query,
		createStack,
		updateStack,
		deleteStack,
		createCard,
	}
}

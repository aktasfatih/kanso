// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { fetchCard, updateCard as apiUpdateCard } from '../services/api.js'
import { boardQueryKey } from './queryKeys.js'

export function useCard(id, enabled) {
	const queryClient = useQueryClient()

	const isEnabled = computed(() => {
		const en = typeof enabled === 'object' ? enabled.value : enabled
		const cardId = typeof id === 'object' ? id.value : id
		return en && !!cardId
	})

	const query = useQuery({
		queryKey: computed(() => ['card', typeof id === 'object' ? id.value : id]),
		queryFn: () => fetchCard(typeof id === 'object' ? id.value : id),
		enabled: isEnabled,
	})

	const updateCard = useMutation({
		mutationFn: ({ data }) =>
			apiUpdateCard(typeof id === 'object' ? id.value : id, data),
		onSettled: (data, _error, variables) => {
			const cardId = typeof id === 'object' ? id.value : id
			queryClient.invalidateQueries({ queryKey: ['card', cardId] })
			// Also invalidate the parent board - we don't know which board the card
			// belongs to here, but we have the result data or can invalidate all boards.
			if (data?.boardId) {
				// boardQueryKey coerces the numeric API boardId to the same string
				// key the board query is registered under (from the route param).
				queryClient.invalidateQueries({ queryKey: boardQueryKey(data.boardId) })
			} else {
				// Invalidate all board queries as a fallback
				queryClient.invalidateQueries({ queryKey: ['board'] })
			}
		},
	})

	return {
		...query,
		updateCard,
	}
}

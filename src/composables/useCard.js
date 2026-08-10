// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { fetchCard, updateCard as apiUpdateCard } from '../services/api.js'
import { boardQueryKey, invalidateMyWork } from './queryKeys.js'

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
			// Also invalidate the parent board so tile-visible mutations (incl. a
			// visibility change, #3743) refetch the scoped board payload. On success
			// `data` is the PATCH response - CardController::update() returns the
			// Card entity via jsonSerialize(), which ALWAYS carries `boardId` - so
			// the targeted branch is taken; the invalidate-all-boards fallback only
			// runs for the error path (onSettled also fires on failure, data null).
			if (data?.boardId) {
				// boardQueryKey coerces the numeric API boardId to the same string
				// key the board query is registered under (from the route param).
				queryClient.invalidateQueries({ queryKey: boardQueryKey(data.boardId) })
			} else {
				// Invalidate all board queries as a fallback
				queryClient.invalidateQueries({ queryKey: ['board'] })
			}
			// Card updates can change My Work membership (done) or the card
			// context the feeds render (title, due date) (#3766).
			invalidateMyWork(queryClient)
		},
	})

	return {
		...query,
		updateCard,
	}
}

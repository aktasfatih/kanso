// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	getProjectCards as apiGetProjectCards,
	addCardToProject as apiAddCardToProject,
	removeCardFromProject as apiRemoveCardFromProject,
} from '../services/api.js'

/**
 * Composable for a single project's card list and add/remove card mutations.
 *
 * @param {import('vue').Ref<string|number>} projectId - reactive project id
 */
export function useProjectCards(projectId) {
	const queryClient = useQueryClient()

	const id = computed(() => (typeof projectId === 'object' ? projectId.value : projectId))

	const query = useQuery({
		queryKey: computed(() => ['project', String(id.value), 'cards']),
		queryFn: () => apiGetProjectCards(id.value),
		enabled: computed(() => id.value != null),
	})

	const addCard = useMutation({
		mutationFn: (cardId) => apiAddCardToProject(id.value, cardId),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: ['project', String(id.value), 'cards'] })
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	const removeCard = useMutation({
		mutationFn: (cardId) => apiRemoveCardFromProject(id.value, cardId),
		onMutate: async (cardId) => {
			const key = ['project', String(id.value), 'cards']
			await queryClient.cancelQueries({ queryKey: key })
			const previous = queryClient.getQueryData(key)
			queryClient.setQueryData(key, (old) => {
				if (!Array.isArray(old)) return old
				return old.filter((c) => c.id !== cardId)
			})
			return { previous }
		},
		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(['project', String(id.value), 'cards'], context.previous)
			}
		},
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: ['project', String(id.value), 'cards'] })
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	return {
		...query,
		addCard,
		removeCard,
	}
}

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	getProjectCards as apiGetProjectCards,
	getProjectPlan as apiGetProjectPlan,
	addCardToProject as apiAddCardToProject,
	removeCardFromProject as apiRemoveCardFromProject,
} from '../services/api.js'

/**
 * Composable for a single project's card list and add/remove card mutations.
 * The mutations take `{ projectId, cardId }`: a write paused offline must not
 * follow the route to whichever project is open at reconnect.
 *
 * @param {import('vue').Ref<string|number>} projectId - reactive project id
 * @param {object} [options]
 * @param {number|false} [options.refetchInterval] poll interval, for a page collaborators may change
 */
export function useProjectCards(projectId, { refetchInterval = false } = {}) {
	const queryClient = useQueryClient()

	const id = computed(() => (typeof projectId === 'object' ? projectId.value : projectId))

	const query = useQuery({
		queryKey: computed(() => ['project', String(id.value), 'cards']),
		queryFn: () => apiGetProjectCards(id.value),
		enabled: computed(() => id.value != null),
		refetchInterval,
	})

	function invalidate(_data, _err, { projectId }) {
		queryClient.invalidateQueries({ queryKey: ['project', String(projectId)] })
		queryClient.invalidateQueries({ queryKey: ['project-stats', String(projectId)] })
		queryClient.invalidateQueries({ queryKey: ['projects'] })
	}

	const addCard = useMutation({
		mutationFn: ({ projectId, cardId }) => apiAddCardToProject(projectId, cardId),
		onSettled: invalidate,
	})

	const removeCard = useMutation({
		mutationFn: ({ projectId, cardId }) => apiRemoveCardFromProject(projectId, cardId),
		onMutate: async ({ projectId, cardId }) => {
			const key = ['project', String(projectId), 'cards']
			await queryClient.cancelQueries({ queryKey: key })
			const previous = queryClient.getQueryData(key)
			queryClient.setQueryData(key, (old) => {
				if (!Array.isArray(old)) return old
				return old.filter((c) => c.id !== cardId)
			})
			return { previous }
		},
		onError: (_err, { projectId }, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(['project', String(projectId), 'cards'], context.previous)
			}
		},
		onSettled: invalidate,
	})

	return {
		...query,
		addCard,
		removeCard,
	}
}

/**
 * A project's progress, forecast, health and burn-up (server-computed).
 *
 * @param {import('vue').Ref<string|number>} projectId - reactive project id
 * @param {object} [options]
 * @param {number|false} [options.refetchInterval] poll interval
 */
export function useProjectPlan(projectId, { refetchInterval = false } = {}) {
	const id = computed(() => (typeof projectId === 'object' ? projectId.value : projectId))

	return useQuery({
		queryKey: computed(() => ['project', String(id.value), 'plan']),
		queryFn: () => apiGetProjectPlan(id.value),
		enabled: computed(() => id.value != null),
		refetchInterval,
	})
}

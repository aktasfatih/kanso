// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { translate as t } from '@nextcloud/l10n'
import {
	getProjectShares as apiGetProjectShares,
	createProjectShare as apiCreateProjectShare,
	updateProjectShare as apiUpdateProjectShare,
	deleteProjectShare as apiDeleteProjectShare,
	searchProjectSharees as apiSearchProjectSharees,
	leaveProject as apiLeaveProject,
} from '../services/api.js'

const MIN_QUERY_LENGTH = 2
const SEARCH_DEBOUNCE_MS = 250

/**
 * A project's share list, its mutations and a debounced sharee search. The list
 * is owner-only, so pass `enabled` false for anyone else.
 *
 * @param {import('vue').Ref<string|number>} projectId reactive project id
 * @param {import('vue').Ref<boolean>} enabled whether to load the share list
 */
export function useProjectShares(projectId, enabled) {
	const queryClient = useQueryClient()
	const queryKey = computed(() => ['project', String(projectId.value), 'shares'])

	const shares = useQuery({
		queryKey,
		queryFn: () => apiGetProjectShares(projectId.value),
		enabled,
	})

	function invalidate() {
		queryClient.invalidateQueries({ queryKey: queryKey.value })
		queryClient.invalidateQueries({ queryKey: ['projects'] })
	}

	const addShare = useMutation({
		mutationFn: (data) => apiCreateProjectShare(projectId.value, data),
		onSettled: invalidate,
	})

	const patchShare = useMutation({
		mutationFn: ({ shareId, permission }) => apiUpdateProjectShare(projectId.value, shareId, permission),
		// Two quick level changes must reach the server in the order they were picked.
		scope: { id: `project-shares-${projectId.value}` },
		onMutate: async ({ shareId, permission }) => {
			await queryClient.cancelQueries({ queryKey: queryKey.value })
			const previous = queryClient.getQueryData(queryKey.value)
			queryClient.setQueryData(queryKey.value, (old) => {
				if (!Array.isArray(old)) return old
				return old.map((share) => share.id === shareId ? { ...share, permission } : share)
			})
			return { previous }
		},
		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(queryKey.value, context.previous)
			}
		},
		onSettled: invalidate,
	})

	const removeShare = useMutation({
		mutationFn: (shareId) => apiDeleteProjectShare(projectId.value, shareId),
		onSettled: invalidate,
	})

	const searchQuery = ref('')
	const searchResults = ref([])
	const isSearching = ref(false)
	const searchError = ref('')
	const searchFinished = ref(false)

	let searchTimer = null
	let latestSearch = 0

	async function runSearch(q) {
		const search = ++latestSearch
		isSearching.value = true
		searchError.value = ''
		try {
			const results = await apiSearchProjectSharees(projectId.value, q)
			if (search === latestSearch) {
				searchResults.value = results
				searchFinished.value = true
			}
		} catch (err) {
			if (search === latestSearch) {
				searchError.value = err?.response?.data?.error || t('kanso', 'Failed to search.')
				searchResults.value = []
			}
		} finally {
			if (search === latestSearch) {
				isSearching.value = false
			}
		}
	}

	function resetSearch() {
		clearTimeout(searchTimer)
		latestSearch++
		searchResults.value = []
		searchError.value = ''
		searchFinished.value = false
		isSearching.value = false
	}

	watch(searchQuery, (q) => {
		resetSearch()
		const query = q.trim()
		if (query.length >= MIN_QUERY_LENGTH) {
			searchTimer = setTimeout(() => runSearch(query), SEARCH_DEBOUNCE_MS)
		}
	})

	onBeforeUnmount(() => clearTimeout(searchTimer))

	function clearSearch() {
		searchQuery.value = ''
		resetSearch()
	}

	return {
		shares,
		addShare,
		patchShare,
		removeShare,
		searchQuery,
		searchResults,
		isSearching,
		searchError,
		searchFinished,
		clearSearch,
	}
}

/**
 * Drops a share the viewer holds directly and clears the project's cached reads,
 * unless a group share keeps them in. Resolves to whether they still have it.
 */
export function useLeaveProject() {
	const queryClient = useQueryClient()

	return useMutation({
		mutationFn: async (projectId) => {
			await apiLeaveProject(projectId)
			await queryClient.invalidateQueries({ queryKey: ['projects'] })
			// A failed refetch keeps the old list, which still shows this project.
			if (queryClient.getQueryState(['projects'])?.status === 'error') return false
			const projects = queryClient.getQueryData(['projects']) ?? []
			return projects.some((project) => String(project.id) === String(projectId))
		},
		onSuccess: (stillListed, projectId) => {
			const id = String(projectId)
			if (stillListed) {
				queryClient.invalidateQueries({ queryKey: ['project', id] })
				return
			}
			queryClient.removeQueries({ queryKey: ['project', id] })
			queryClient.removeQueries({ queryKey: ['project-stats', id] })
		},
		onError: () => {
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})
}

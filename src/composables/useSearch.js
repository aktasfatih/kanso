// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, computed, watch } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { search as apiSearch } from '../services/api.js'

/**
 * Debounced card/comment search scoped to a single board.
 *
 * @param {import('vue').Ref<string>} term  - reactive search string (raw, not debounced)
 * @param {import('vue').Ref<string|number>} boardId  - board to search within
 * @param {number} [debounceMs=250] - debounce delay in milliseconds
 * @returns {{ results, total, isFetching, debouncedTerm }}
 */
export function useSearch(term, boardId, debounceMs = 250) {
	// Manual debounce — @vueuse/core is not in the dependency tree
	const debouncedTerm = ref(term.value ?? '')
	let debounceTimer = null

	watch(term, (newVal) => {
		clearTimeout(debounceTimer)
		debounceTimer = setTimeout(() => {
			debouncedTerm.value = newVal ?? ''
		}, debounceMs)
	})

	const isEnabled = computed(() => {
		const t = debouncedTerm.value ?? ''
		return t.length >= 2
	})

	const queryKey = computed(() => [
		'search',
		debouncedTerm.value,
		typeof boardId === 'object' ? boardId.value : boardId,
	])

	const { data, isFetching } = useQuery({
		queryKey,
		queryFn: () =>
			apiSearch({
				q: debouncedTerm.value,
				boardId: typeof boardId === 'object' ? boardId.value : boardId,
			}),
		enabled: isEnabled,
		// Search results should never be served stale — always refetch on focus
		staleTime: 0,
	})

	const results = computed(() => data.value?.results ?? [])
	const total = computed(() => data.value?.total ?? 0)

	return { results, total, isFetching, debouncedTerm }
}

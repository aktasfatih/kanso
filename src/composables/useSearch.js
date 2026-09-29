// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, computed, watch, unref } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { search as apiSearch } from '../services/api.js'

/**
 * Debounced card/comment search scoped to a single board.
 *
 * `includeArchived` is the ONE dimension beyond the term (#10762). Archived
 * cards are excluded server-side by default - auto-archive sweeps finished work
 * off the board continuously, so without that baseline a long-lived board's
 * results are mostly shelved cards. The flag only ever widens; it is part of the
 * query key, so flipping it refetches rather than serving the narrow result set.
 *
 * @param {import('vue').Ref<string>} term  - reactive search string (raw, not debounced)
 * @param {import('vue').Ref<string|number>} boardId  - board to search within
 * @param {import('vue').Ref<boolean>|boolean} [includeArchived=false] - widen to archived cards
 * @param {number} [debounceMs=250] - debounce delay in milliseconds
 * @returns {{ results, total, isFetching, debouncedTerm }}
 */
export function useSearch(term, boardId, includeArchived = false, debounceMs = 250) {
	// Manual debounce - @vueuse/core is not in the dependency tree
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

	// Both callers may hand these over as a ref or as a plain value, so unref()
	// them once rather than re-deriving the shape at every use.
	const scopedBoardId = computed(() => unref(boardId))
	const archivedIncluded = computed(() => unref(includeArchived) === true)

	const queryKey = computed(() => [
		'search',
		debouncedTerm.value,
		scopedBoardId.value,
		archivedIncluded.value,
	])

	const { data, isFetching } = useQuery({
		queryKey,
		queryFn: () =>
			apiSearch({
				q: debouncedTerm.value,
				boardId: scopedBoardId.value,
				includeArchived: archivedIncluded.value,
			}),
		enabled: isEnabled,
		// Search results should never be served stale - always refetch on focus
		staleTime: 0,
	})

	const results = computed(() => data.value?.results ?? [])
	const total = computed(() => data.value?.total ?? 0)

	return { results, total, isFetching, debouncedTerm }
}

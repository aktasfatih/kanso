// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, watch } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	searchSharees as apiSearchSharees,
	createAcl as apiCreateAcl,
	updateAcl as apiUpdateAcl,
	deleteAcl as apiDeleteAcl,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'

/**
 * ACL mutations and a debounced sharee search helper for a given board.
 *
 * All mutations invalidate the board query on settled - same low-frequency,
 * server-authoritative pattern as useLabels.
 */
export function useAcl(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		const id = typeof boardId === 'function' ? boardId()
			: (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined ? boardId.value : boardId)
		return boardQueryKey(id)
	}

	function rawBoardId() {
		if (typeof boardId === 'function') return boardId()
		if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
		return boardId
	}

	// ── Mutations ──────────────────────────────────────────────────────────────

	const addAcl = useMutation({
		mutationFn: (data) => apiCreateAcl(rawBoardId(), data),
		onSettled: () => queryClient.invalidateQueries({ queryKey: getBoardKey() }),
	})

	const patchAcl = useMutation({
		mutationFn: ({ aclId, permission }) => apiUpdateAcl(rawBoardId(), aclId, permission),
		onSettled: () => queryClient.invalidateQueries({ queryKey: getBoardKey() }),
	})

	const removeAcl = useMutation({
		mutationFn: ({ aclId }) => apiDeleteAcl(rawBoardId(), aclId),
		onSettled: () => queryClient.invalidateQueries({ queryKey: getBoardKey() }),
	})

	// ── Debounced sharee search ────────────────────────────────────────────────
	// Returns reactive state the UI can bind to directly.
	// enabled when query.length >= 2, debounced 250 ms.

	const searchQuery = ref('')
	const searchResults = ref([])
	const isSearching = ref(false)
	const searchError = ref('')

	let searchTimer = null

	async function runSearch(q) {
		if (q.length < 2) {
			searchResults.value = []
			searchError.value = ''
			return
		}
		isSearching.value = true
		searchError.value = ''
		try {
			searchResults.value = await apiSearchSharees(rawBoardId(), q)
		} catch (err) {
			searchError.value = err?.response?.data?.error || ''
			searchResults.value = []
		} finally {
			isSearching.value = false
		}
	}

	watch(searchQuery, (q) => {
		clearTimeout(searchTimer)
		if (q.length < 2) {
			searchResults.value = []
			searchError.value = ''
			isSearching.value = false
			return
		}
		searchTimer = setTimeout(() => runSearch(q), 250)
	})

	function clearSearch() {
		clearTimeout(searchTimer)
		searchQuery.value = ''
		searchResults.value = []
		searchError.value = ''
		isSearching.value = false
	}

	return {
		addAcl,
		patchAcl,
		removeAcl,
		searchQuery,
		searchResults,
		isSearching,
		searchError,
		clearSearch,
	}
}

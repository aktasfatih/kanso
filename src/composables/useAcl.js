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
import { participantsQueryKey } from './queryKeys.js'

/**
 * ACL mutations and a debounced sharee search helper for a given board.
 *
 * All mutations invalidate the board query on settled - same low-frequency,
 * server-authoritative pattern as useLabels - AND the board's participant list,
 * because who can be assigned a card is derived from exactly these rules.
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

	// Every ACL mutation settles through here. The board query is the obvious
	// half; the participant list is the half that used to be missed. It is a
	// SEPARATE cache entry (useAssignees owns it, with a deliberate 3-minute
	// staleTime) and the sharing dialog was its only blind spot: nothing else in
	// the app changes who has access, so nothing else could invalidate it. Sharing
	// a board therefore left the sharer's own tab serving the pre-share list until
	// a hard reload - in the assignee picker, in the BoardFilterBar assignee/owner
	// facets and in the @-mention autocomplete, all three of which read this one
	// key. Revokes had the mirror-image staleness.
	//
	// All three mutations invalidate it, not just the add: a revoke must drop the
	// user from the picker, and a permission/role change settles the same way so
	// the three stay symmetric (the payload is uid + displayName, so that one is a
	// cheap no-op refetch on a rare admin action rather than a correctness fix).
	//
	// Not covered here, deliberately: the RECIPIENT's already-open tabs. The
	// server does append a Change::ENTITY_ACL row (AclService::create), but
	// useBoardDelta only consumes ENTITY_CARD rows, so a share still lands in
	// their other tabs on the next full board read. That is its own change.
	function invalidateAcl() {
		queryClient.invalidateQueries({ queryKey: getBoardKey() })
		queryClient.invalidateQueries({ queryKey: participantsQueryKey(rawBoardId()) })
	}

	const addAcl = useMutation({
		mutationFn: (data) => apiCreateAcl(rawBoardId(), data),
		onSettled: invalidateAcl,
	})

	const patchAcl = useMutation({
		// role is optional; undefined is dropped from the JSON body and the
		// server keeps the stored board side (internal/external) untouched.
		mutationFn: ({ aclId, permission, role }) => apiUpdateAcl(rawBoardId(), aclId, permission, role),
		onSettled: invalidateAcl,
	})

	const removeAcl = useMutation({
		mutationFn: ({ aclId }) => apiDeleteAcl(rawBoardId(), aclId),
		onSettled: invalidateAcl,
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

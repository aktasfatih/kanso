// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchArchiveRules as apiFetchArchiveRules,
	createArchiveRule as apiCreateArchiveRule,
	updateArchiveRule as apiUpdateArchiveRule,
	deleteArchiveRule as apiDeleteArchiveRule,
	archiveNow as apiArchiveNow,
} from '../services/api.js'

/**
 * Resolve a boardId that may be a plain value, a Vue ref, or a getter fn.
 */
function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

export function useArchiveRules(boardId) {
	const queryClient = useQueryClient()

	function getQueryKey() {
		return ['archive-rules', resolveBoardId(boardId)]
	}

	function invalidate() {
		queryClient.invalidateQueries({ queryKey: getQueryKey() })
	}

	// ── Fetch ─────────────────────────────────────────────────────────────────
	const query = useQuery({
		queryKey: ['archive-rules', boardId],
		queryFn: () => apiFetchArchiveRules(resolveBoardId(boardId)),
	})

	// ── Create ────────────────────────────────────────────────────────────────
	const createRule = useMutation({
		mutationFn: (data) => apiCreateArchiveRule(resolveBoardId(boardId), data),
		onSettled: invalidate,
	})

	// ── Update ────────────────────────────────────────────────────────────────
	const updateRule = useMutation({
		mutationFn: ({ id, data }) => apiUpdateArchiveRule(id, data),
		onSettled: invalidate,
	})

	// ── Delete ────────────────────────────────────────────────────────────────
	const deleteRule = useMutation({
		mutationFn: (id) => apiDeleteArchiveRule(id),
		onSettled: invalidate,
	})

	// ── Archive now ───────────────────────────────────────────────────────────
	// Returns the archived count from the server response { archived: N }.
	// The caller is responsible for displaying the count.
	const archiveNow = useMutation({
		mutationFn: (id) => apiArchiveNow(id),
		onSettled: invalidate,
	})

	return {
		...query,
		createRule,
		updateRule,
		deleteRule,
		archiveNow,
	}
}

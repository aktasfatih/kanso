// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchRecurRules as apiFetchRecurRules,
	createRecurRule as apiCreateRecurRule,
	updateRecurRule as apiUpdateRecurRule,
	deleteRecurRule as apiDeleteRecurRule,
	createNowRecurRule as apiCreateNowRecurRule,
} from '../services/api.js'

/**
 * Resolve a boardId that may be a plain value, a Vue ref, or a getter fn.
 * Mirrors the resolveBoardId pattern used in useAssignees.js / useArchiveRules.js.
 */
function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

export function useRecurRules(boardId, { enabled } = {}) {
	const queryClient = useQueryClient()

	function getQueryKey() {
		return ['recur-rules', resolveBoardId(boardId)]
	}

	function invalidate() {
		queryClient.invalidateQueries({ queryKey: getQueryKey() })
	}

	// ── Fetch ─────────────────────────────────────────────────────────────────
	// `enabled` lets a caller hold the fetch until the board id has resolved -
	// the full-page card route only learns its boardId after the card loads, and
	// firing with an empty id would 404 (and spam the console). Undefined keeps
	// the default always-on behaviour for the board-settings caller.
	const query = useQuery({
		queryKey: ['recur-rules', boardId],
		queryFn: () => apiFetchRecurRules(resolveBoardId(boardId)),
		enabled,
	})

	// ── Create ────────────────────────────────────────────────────────────────
	const createRule = useMutation({
		mutationFn: (data) => apiCreateRecurRule(resolveBoardId(boardId), data),
		onSettled: invalidate,
	})

	// ── Update ────────────────────────────────────────────────────────────────
	const updateRule = useMutation({
		mutationFn: ({ id, data }) => apiUpdateRecurRule(id, data),
		onSettled: invalidate,
	})

	// ── Delete ────────────────────────────────────────────────────────────────
	const deleteRule = useMutation({
		mutationFn: (id) => apiDeleteRecurRule(id),
		onSettled: invalidate,
	})

	// ── Create now ────────────────────────────────────────────────────────────
	// Spawns one card immediately from the rule's template.
	// Returns whatever the server sends back (typically the created card).
	const createNow = useMutation({
		mutationFn: (id) => apiCreateNowRecurRule(id),
		onSettled: invalidate,
	})

	return {
		...query,
		createRule,
		updateRule,
		deleteRule,
		createNow,
	}
}

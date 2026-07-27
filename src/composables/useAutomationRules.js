// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchAutomationRules as apiFetchAutomationRules,
	createAutomationRule as apiCreateAutomationRule,
	setAutomationRuleEnabled as apiSetAutomationRuleEnabled,
	deleteAutomationRule as apiDeleteAutomationRule,
} from '../services/api.js'

/**
 * Resolve a boardId that may be a plain value, a Vue ref, or a getter fn.
 * Mirrors the resolveBoardId pattern used in useRecurRules.js / useArchiveRules.js.
 */
function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

export function useAutomationRules(boardId) {
	const queryClient = useQueryClient()

	function invalidate() {
		queryClient.invalidateQueries({ queryKey: ['automation-rules', resolveBoardId(boardId)] })
	}

	const query = useQuery({
		queryKey: ['automation-rules', boardId],
		queryFn: () => apiFetchAutomationRules(resolveBoardId(boardId)),
	})

	const createRule = useMutation({
		mutationFn: (data) => apiCreateAutomationRule(resolveBoardId(boardId), data),
		onSettled: invalidate,
	})

	const setEnabled = useMutation({
		mutationFn: ({ id, enabled }) => apiSetAutomationRuleEnabled(id, enabled),
		onSettled: invalidate,
	})

	const deleteRule = useMutation({
		mutationFn: (id) => apiDeleteAutomationRule(id),
		onSettled: invalidate,
	})

	return {
		...query,
		createRule,
		setEnabled,
		deleteRule,
	}
}

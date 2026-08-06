// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useCardTimeEntries - a card's manual time-tracking entries (#3536).
 *
 * Loaded separately from the card detail (like comments/attachments): the list
 * of entries stays off the hot card-open path, while the per-card TOTAL travels
 * in the card detail payload as `timeSpent`. Add/delete are mutations that
 * invalidate the list; delete is optimistic with rollback (mirrors
 * useCardAttachments' remove).
 *
 * `cardId` may be a plain value, a Vue ref, or a getter function.
 */

import { computed, unref } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchCardTimeEntries,
	addCardTimeEntry as apiAdd,
	deleteCardTimeEntry as apiDelete,
} from '../services/api.js'

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} cardId
 */
export function useCardTimeEntries(cardId) {
	const queryClient = useQueryClient()

	const resolvedId = computed(() => {
		const v = typeof cardId === 'function' ? cardId() : unref(cardId)
		return String(v)
	})

	const key = computed(() => ['card-time-entries', resolvedId.value])

	const query = useQuery({
		queryKey: key,
		queryFn: () => fetchCardTimeEntries(resolvedId.value),
	})

	const addEntry = useMutation({
		mutationFn: ({ seconds, note }) => apiAdd(resolvedId.value, seconds, note),
		onSettled: () => queryClient.invalidateQueries({ queryKey: key.value }),
	})

	const removeEntry = useMutation({
		mutationFn: (entryId) => apiDelete(resolvedId.value, entryId),
		onMutate: async (entryId) => {
			await queryClient.cancelQueries({ queryKey: key.value })
			const previous = queryClient.getQueryData(key.value)
			queryClient.setQueryData(key.value, (old) =>
				Array.isArray(old) ? old.filter((e) => e.id !== entryId) : old,
			)
			return { previous }
		},
		onError: (_err, _id, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(key.value, context.previous)
			}
		},
		onSettled: () => queryClient.invalidateQueries({ queryKey: key.value }),
	})

	return { ...query, timeEntries: query.data, addEntry, removeEntry }
}

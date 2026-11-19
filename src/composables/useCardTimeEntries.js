// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, unref } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { boardQueryKey } from './queryKeys.js'
import {
	fetchCardTimeEntries,
	addCardTimeEntry as apiAdd,
	deleteCardTimeEntry as apiDelete,
	startCardTimer,
	stopCardTimer,
} from '../services/api.js'

/** @param {import('vue').Ref<string|number>|string|number|Function} cardId */
export function useCardTimeEntries(cardId) {
	const queryClient = useQueryClient()
	const resolvedId = computed(() => String(typeof cardId === 'function' ? cardId() : unref(cardId)))
	const key = computed(() => ['card-time-entries', resolvedId.value])

	const query = useQuery({
		queryKey: key,
		queryFn: ({ queryKey }) => fetchCardTimeEntries(queryKey[1]),
	})

	function invalidate(_data, _error, { cardId: card, boardId }) {
		const queries = [
			['card-time-entries', String(card)],
			['card', String(card)],
		]
		if (boardId != null) queries.push(boardQueryKey(boardId), ['board-time-stats', String(boardId)])
		return Promise.all(queries.map((queryKey) => queryClient.invalidateQueries({ queryKey })))
	}

	const addEntry = useMutation({
		mutationFn: ({ cardId: card, seconds, note, actorName }) => apiAdd(card, seconds, note, actorName),
		onSettled: invalidate,
	})

	const removeEntry = useMutation({
		mutationFn: ({ cardId: card, entryId }) => apiDelete(card, entryId),
		onMutate: async ({ cardId: card, entryId }) => {
			const entryKey = ['card-time-entries', String(card)]
			await queryClient.cancelQueries({ queryKey: entryKey })
			const previous = queryClient.getQueryData(entryKey)
			queryClient.setQueryData(entryKey, (old) =>
				Array.isArray(old) ? old.filter((entry) => entry.id !== entryId) : old,
			)
			return { entryKey, previous }
		},
		onError: (_error, _variables, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(context.entryKey, context.previous)
			}
		},
		onSettled: invalidate,
	})

	const startTimer = useMutation({
		mutationFn: ({ cardId: card, actorName }) => startCardTimer(card, actorName),
		onSettled: invalidate,
	})
	const stopTimer = useMutation({
		mutationFn: ({ cardId: card }) => stopCardTimer(card),
		onSettled: invalidate,
	})

	return { ...query, timeEntries: query.data, addEntry, removeEntry, startTimer, stopTimer }
}

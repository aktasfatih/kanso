// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, unref } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchCardFileLinks,
	addCardFileLink as apiAddCardFileLink,
	deleteCardFileLink as apiDeleteCardFileLink,
} from '../services/api.js'

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} cardId
 */
export function useCardFileLinks(cardId) {
	const queryClient = useQueryClient()

	const resolvedId = computed(() => {
		const v = typeof cardId === 'function' ? cardId() : unref(cardId)
		return String(v)
	})

	const key = computed(() => ['card-file-links', resolvedId.value])

	const query = useQuery({
		queryKey: key,
		queryFn: () => fetchCardFileLinks(resolvedId.value),
	})

	const addFileLink = useMutation({
		mutationFn: (fileId) => apiAddCardFileLink(resolvedId.value, fileId),
		onSettled: () => queryClient.invalidateQueries({ queryKey: key.value }),
	})

	const removeFileLink = useMutation({
		mutationFn: (linkId) => apiDeleteCardFileLink(resolvedId.value, linkId),
		onMutate: async (linkId) => {
			await queryClient.cancelQueries({ queryKey: key.value })
			const previous = queryClient.getQueryData(key.value)
			queryClient.setQueryData(key.value, (old) =>
				Array.isArray(old) ? old.filter((l) => l.id !== linkId) : old,
			)
			return { previous }
		},
		onError: (_err, _linkId, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(key.value, context.previous)
			}
		},
		onSettled: () => queryClient.invalidateQueries({ queryKey: key.value }),
	})

	return { ...query, fileLinks: query.data, addFileLink, removeFileLink }
}

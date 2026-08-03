// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useCardAttachments - a card's file attachments (#3526).
 *
 * Loaded separately from the card detail (like comments/links): keeps the byte
 * count and metadata off the hot card-open path. Upload/delete are mutations
 * that invalidate the list; delete is optimistic with rollback (mirrors
 * useCardLinks' remove).
 *
 * `cardId` may be a plain value, a Vue ref, or a getter function.
 */

import { computed, unref } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchCardAttachments,
	uploadCardAttachment as apiUpload,
	deleteCardAttachment as apiDelete,
} from '../services/api.js'

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} cardId
 */
export function useCardAttachments(cardId) {
	const queryClient = useQueryClient()

	const resolvedId = computed(() => {
		const v = typeof cardId === 'function' ? cardId() : unref(cardId)
		return String(v)
	})

	const key = computed(() => ['card-attachments', resolvedId.value])

	const query = useQuery({
		queryKey: key,
		queryFn: () => fetchCardAttachments(resolvedId.value),
	})

	const uploadAttachment = useMutation({
		mutationFn: (file) => apiUpload(resolvedId.value, file),
		onSettled: () => queryClient.invalidateQueries({ queryKey: key.value }),
	})

	const removeAttachment = useMutation({
		mutationFn: (attachmentId) => apiDelete(resolvedId.value, attachmentId),
		onMutate: async (attachmentId) => {
			await queryClient.cancelQueries({ queryKey: key.value })
			const previous = queryClient.getQueryData(key.value)
			queryClient.setQueryData(key.value, (old) =>
				Array.isArray(old) ? old.filter((a) => a.id !== attachmentId) : old,
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

	return { ...query, attachments: query.data, uploadAttachment, removeAttachment }
}

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
 * `cardId` may be a plain value, a Vue ref, or a getter function; `boardId` the
 * same, and it is what lets these mutations reach the BOARD-wide listing too.
 */

import { computed, unref } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchCardAttachments,
	uploadCardAttachment as apiUpload,
	attachCardFileFromFiles as apiAttachFromFiles,
	deleteCardAttachment as apiDelete,
} from '../services/api.js'
import { boardAttachmentsQueryKey } from './queryKeys.js'

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} cardId
 * @param {import('vue').Ref<string|number>|string|number|Function} [boardId] the
 *   card's board - omit only where it genuinely is not known; without it the
 *   board-wide attachments modal cannot be told its list changed.
 */
export function useCardAttachments(cardId, boardId) {
	const queryClient = useQueryClient()

	const resolvedId = computed(() => {
		const v = typeof cardId === 'function' ? cardId() : unref(cardId)
		return String(v)
	})

	const key = computed(() => ['card-attachments', resolvedId.value])

	// The board-wide listing (#10670) shows exactly the rows these mutations
	// add and remove, from a DIFFERENT cache key - so every settle has to
	// invalidate both or the modal serves a stale list until a reload (#10738).
	// Through the shared String-coercing helper, never a hand-spelled key.
	const boardKey = computed(() => {
		const v = typeof boardId === 'function' ? boardId() : unref(boardId)
		return (v === undefined || v === null || v === '') ? null : boardAttachmentsQueryKey(v)
	})

	/**
	 * Settle-phase invalidation for all three mutations: the card's own list
	 * plus the board-wide one.
	 */
	function invalidateLists() {
		queryClient.invalidateQueries({ queryKey: key.value })
		if (boardKey.value !== null) {
			queryClient.invalidateQueries({ queryKey: boardKey.value })
		}
	}

	const query = useQuery({
		queryKey: key,
		queryFn: () => fetchCardAttachments(resolvedId.value),
	})

	const uploadAttachment = useMutation({
		mutationFn: (file) => apiUpload(resolvedId.value, file),
		onSettled: () => invalidateLists(),
	})

	// "Choose from Files": the server COPIES the picked Files node's bytes into
	// the card's app-data, so the result is an ordinary attachment row - same
	// invalidation as an upload, no optimistic row (the filename/size/mime are
	// the server's, not the picker's).
	const attachFromFiles = useMutation({
		mutationFn: (fileId) => apiAttachFromFiles(resolvedId.value, fileId),
		onSettled: () => invalidateLists(),
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
		onSettled: () => invalidateLists(),
	})

	return { ...query, attachments: query.data, uploadAttachment, attachFromFiles, removeAttachment }
}

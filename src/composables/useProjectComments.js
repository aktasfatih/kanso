// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useProjectComments — the owner-only project discussion log (#3563).
 *
 * A thinner twin of useComments (cards): a project comment has exactly one
 * reader (the owner), so there is no card/board cache to patch and no comment
 * count badge to keep in sync — just the flat comment list for one project,
 * with optimistic add/edit/delete and rollback on error.
 *
 * The flat comment array from the server is returned as-is; tree building
 * (top-level + one level of replies) is done by `buildCommentTree` (shared with
 * card comments — the server contract is identical: replies always point to a
 * top-level comment).
 *
 * projectId may be a plain number/string, a Vue ref, or a getter function.
 */

import { computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchProjectComments as apiFetch,
	createProjectComment as apiCreate,
	updateProjectComment as apiUpdate,
	deleteProjectComment as apiDelete,
} from '../services/api.js'

/**
 * Resolve a value that may be a plain primitive, a Vue ref, or a getter fn.
 * @param {any} v
 * @returns {any}
 */
function resolve(v) {
	if (typeof v === 'function') return v()
	if (v !== null && typeof v === 'object' && 'value' in v) return v.value
	return v
}

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} projectId
 */
export function useProjectComments(projectId) {
	const queryClient = useQueryClient()

	function getProjectId() {
		return String(resolve(projectId))
	}

	function getKey() {
		return ['project', getProjectId(), 'comments']
	}

	const comments = useQuery({
		queryKey: computed(() => getKey()),
		queryFn: () => apiFetch(getProjectId()),
		enabled: computed(() => resolve(projectId) != null),
	})

	// ── addComment ────────────────────────────────────────────────────────────
	const addComment = useMutation({
		mutationFn: ({ body, parentCommentId }) =>
			apiCreate(getProjectId(), {
				body,
				...(parentCommentId !== null && parentCommentId !== undefined
					? { parentCommentId }
					: {}),
			}),

		onMutate: async ({ body, parentCommentId }) => {
			const key = getKey()
			await queryClient.cancelQueries({ queryKey: key })
			const previous = queryClient.getQueryData(key)

			const current = Array.isArray(previous) ? previous : []
			const tempId = -(Date.now())
			const optimistic = {
				id: tempId,
				projectId: Number(getProjectId()),
				parentCommentId: parentCommentId ?? null,
				author: '',
				authorDisplayName: '',
				body,
				createdAt: Math.floor(Date.now() / 1000),
				editedAt: 0,
				_optimistic: true,
			}
			queryClient.setQueryData(key, [...current, optimistic])

			return { previous }
		},

		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(getKey(), context.previous)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getKey() })
		},
	})

	// ── editComment ───────────────────────────────────────────────────────────
	const editComment = useMutation({
		mutationFn: ({ comment, body }) => apiUpdate(comment.id, { body }),

		onMutate: async ({ comment, body }) => {
			const key = getKey()
			await queryClient.cancelQueries({ queryKey: key })
			const previous = queryClient.getQueryData(key)

			const next = (Array.isArray(previous) ? previous : []).map((c) =>
				c.id === comment.id
					? { ...c, body, editedAt: Math.floor(Date.now() / 1000) }
					: c,
			)
			queryClient.setQueryData(key, next)

			return { previous }
		},

		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(getKey(), context.previous)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getKey() })
		},
	})

	// ── deleteComment ─────────────────────────────────────────────────────────
	// Deleting a top-level comment also removes its replies server-side; mirror
	// that optimistically by dropping the comment and any of its direct children.
	const deleteComment = useMutation({
		mutationFn: ({ comment }) => apiDelete(comment.id),

		onMutate: async ({ comment }) => {
			const key = getKey()
			await queryClient.cancelQueries({ queryKey: key })
			const previous = queryClient.getQueryData(key)

			const current = Array.isArray(previous) ? previous : []
			const removedId = Number(comment.id)
			const next = current.filter(
				(c) => c.id !== comment.id && Number(c.parentCommentId) !== removedId,
			)
			queryClient.setQueryData(key, next)

			return { previous }
		},

		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(getKey(), context.previous)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getKey() })
		},
	})

	return {
		comments,
		addComment,
		editComment,
		deleteComment,
	}
}

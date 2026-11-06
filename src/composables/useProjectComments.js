// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useProjectComments — a project's discussion log.
 *
 * A thinner twin of useComments (cards): there is no card/board cache to patch
 * and no comment count badge to keep in sync — just the flat comment list for
 * one project, with optimistic add/edit/delete and rollback on error.
 *
 * The flat comment array from the server is returned as-is; tree building
 * (top-level + one level of replies) is done by `buildCommentTree` (shared with
 * card comments — the server contract is identical: replies always point to a
 * top-level comment).
 *
 * projectId may be a plain number/string, a Vue ref, or a getter function. It
 * drives the read only: every write takes its project from the mutation
 * variables, because one paused offline must not follow the route.
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

	function keyFor(id) {
		return ['project', String(id), 'comments']
	}

	// A refused write means the viewer's access changed, which ['projects'] carries.
	function invalidate(_data, _err, { projectId: id }) {
		queryClient.invalidateQueries({ queryKey: keyFor(id) })
		queryClient.invalidateQueries({ queryKey: ['projects'] })
	}

	function rollback(_err, { projectId: id }, context) {
		if (context?.previous !== undefined) {
			queryClient.setQueryData(keyFor(id), context.previous)
		}
	}

	const comments = useQuery({
		queryKey: computed(() => keyFor(getProjectId())),
		queryFn: () => apiFetch(getProjectId()),
		enabled: computed(() => resolve(projectId) != null),
	})

	// ── addComment ────────────────────────────────────────────────────────────
	const addComment = useMutation({
		mutationFn: ({ projectId: id, body, parentCommentId }) =>
			apiCreate(String(id), {
				body,
				...(parentCommentId !== null && parentCommentId !== undefined
					? { parentCommentId }
					: {}),
			}),

		onMutate: async ({ projectId: id, body, parentCommentId }) => {
			const key = keyFor(id)
			await queryClient.cancelQueries({ queryKey: key })
			const previous = queryClient.getQueryData(key)

			const current = Array.isArray(previous) ? previous : []
			const tempId = -(Date.now())
			const optimistic = {
				id: tempId,
				projectId: Number(id),
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

		onError: rollback,

		onSettled: invalidate,
	})

	// ── editComment ───────────────────────────────────────────────────────────
	const editComment = useMutation({
		mutationFn: ({ comment, body }) => apiUpdate(comment.id, { body }),

		onMutate: async ({ projectId: id, comment, body }) => {
			const key = keyFor(id)
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

		onError: rollback,

		onSettled: invalidate,
	})

	// ── deleteComment ─────────────────────────────────────────────────────────
	// Deleting a top-level comment also removes its replies server-side; mirror
	// that optimistically by dropping the comment and any of its direct children.
	const deleteComment = useMutation({
		mutationFn: ({ comment }) => apiDelete(comment.id),

		onMutate: async ({ projectId: id, comment }) => {
			const key = keyFor(id)
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

		onError: rollback,

		onSettled: invalidate,
	})

	return {
		comments,
		addComment,
		editComment,
		deleteComment,
	}
}

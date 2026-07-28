// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useComments - threaded comment query and mutations for a single card.
 *
 * Optimistic strategy mirrors useChecklist (dual-cache pattern):
 *   1. Cancel in-flight queries for the comments key, the card detail key,
 *      and the board key.
 *   2. Snapshot previous values for rollback on error.
 *   3. Optimistically patch the comments cache AND the card detail cache
 *      (commentCount field).
 *   4. ALSO patch the board cache's per-card commentCount so the tile badge
 *      updates immediately without waiting for the settle invalidation.
 *   5. On settled: invalidate the comments query, the card detail query,
 *      and the board query so server truth eventually wins.
 *
 * The flat comment array from the server is returned as-is; tree building
 * (top-level + replies) is done in the consuming component via `buildTree`.
 *
 * The composable accepts a cardId that may be:
 *   - a plain number/string
 *   - a Vue ref
 *   - a getter function (e.g. () => props.cardId)
 * …and a boardId with the same flexibility.
 */

import { computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchComments as apiFetchComments,
	createComment as apiCreateComment,
	updateComment as apiUpdateComment,
	deleteComment as apiDeleteComment,
} from '../services/api.js'
import { boardQueryKey } from './queryKeys.js'

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
 * Build a one-level thread tree from the flat comment array.
 * Top-level comments (parentCommentId === null) are returned in order,
 * each with a `replies` array of its direct children (also in order).
 *
 * The server contract allows only one level of nesting - replies always
 * point to a top-level comment, never to another reply.
 *
 * @param {Array} flat
 * @returns {Array<{comment: object, replies: object[]}>}
 */
export function buildCommentTree(flat) {
	if (!Array.isArray(flat)) return []

	const topLevel = flat.filter((c) => c.parentCommentId === null || c.parentCommentId === undefined)
	const byParent = new Map()
	for (const c of flat) {
		if (c.parentCommentId !== null && c.parentCommentId !== undefined) {
			const pid = Number(c.parentCommentId)
			if (!byParent.has(pid)) byParent.set(pid, [])
			byParent.get(pid).push(c)
		}
	}

	return topLevel.map((c) => ({
		comment: c,
		replies: byParent.get(Number(c.id)) ?? [],
	}))
}

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} cardId
 * @param {import('vue').Ref<string|number>|string|number|Function} boardId
 */
export function useComments(cardId, boardId) {
	const queryClient = useQueryClient()

	function getCardId() {
		return String(resolve(cardId))
	}

	function getCommentsKey() {
		return ['comments', getCardId()]
	}

	function getCardKey() {
		return ['card', getCardId()]
	}

	function getBoardKey() {
		return boardQueryKey(resolve(boardId))
	}

	// ── Shared helper: patch card detail commentCount in place ──────────────────
	function patchCardDetailCommentCount(count) {
		queryClient.setQueryData(getCardKey(), (old) => {
			if (!old) return old
			return { ...old, commentCount: count }
		})
	}

	// ── Shared helper: patch board card commentCount in place ───────────────────
	function patchBoardCardCommentCount(count) {
		const boardKey = getBoardKey()
		queryClient.setQueryData(boardKey, (old) => {
			if (!old) return old
			const numericCardId = Number(getCardId())
			return {
				...old,
				cards: old.cards.map((c) =>
					c.id === numericCardId ? { ...c, commentCount: count } : c,
				),
			}
		})
	}

	// ── Comments query ──────────────────────────────────────────────────────────
	const comments = useQuery({
		queryKey: computed(() => getCommentsKey()),
		queryFn: () => apiFetchComments(getCardId()),
	})

	// ── addComment ──────────────────────────────────────────────────────────────
	const addComment = useMutation({
		mutationFn: ({ body, parentCommentId }) =>
			apiCreateComment(getCardId(), {
				body,
				...(parentCommentId !== null && parentCommentId !== undefined
					? { parentCommentId }
					: {}),
			}),

		onMutate: async ({ body, parentCommentId }) => {
			const commentsKey = getCommentsKey()
			const cardKey = getCardKey()
			const boardKey = getBoardKey()

			await queryClient.cancelQueries({ queryKey: commentsKey })
			await queryClient.cancelQueries({ queryKey: cardKey })
			await queryClient.cancelQueries({ queryKey: boardKey })

			const previousComments = queryClient.getQueryData(commentsKey)
			const previousCard = queryClient.getQueryData(cardKey)
			const previousBoard = queryClient.getQueryData(boardKey)

			const current = Array.isArray(previousComments) ? previousComments : []
			const tempId = -(Date.now())
			const optimisticComment = {
				id: tempId,
				cardId: Number(getCardId()),
				parentCommentId: parentCommentId ?? null,
				author: '',
				authorDisplayName: '',
				body,
				createdAt: Math.floor(Date.now() / 1000),
				editedAt: 0,
				_optimistic: true,
			}
			const nextComments = [...current, optimisticComment]
			queryClient.setQueryData(commentsKey, nextComments)

			const newCount = (previousCard?.commentCount ?? 0) + 1
			patchCardDetailCommentCount(newCount)
			patchBoardCardCommentCount(newCount)

			return { previousComments, previousCard, previousBoard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousComments !== undefined) {
				queryClient.setQueryData(getCommentsKey(), context.previousComments)
			}
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCommentsKey() })
			queryClient.invalidateQueries({ queryKey: getCardKey() })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── editComment ─────────────────────────────────────────────────────────────
	const editComment = useMutation({
		mutationFn: ({ comment, body }) => apiUpdateComment(comment.id, { body }),

		onMutate: async ({ comment, body }) => {
			const commentsKey = getCommentsKey()
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: commentsKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousComments = queryClient.getQueryData(commentsKey)
			const previousCard = queryClient.getQueryData(cardKey)

			const nextComments = (Array.isArray(previousComments) ? previousComments : []).map((c) =>
				c.id === comment.id
					? { ...c, body, editedAt: Math.floor(Date.now() / 1000) }
					: c,
			)
			queryClient.setQueryData(commentsKey, nextComments)

			return { previousComments, previousCard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousComments !== undefined) {
				queryClient.setQueryData(getCommentsKey(), context.previousComments)
			}
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCommentsKey() })
			queryClient.invalidateQueries({ queryKey: getCardKey() })
		},
	})

	// ── deleteComment ───────────────────────────────────────────────────────────
	// Deleting a top-level comment also removes its replies server-side.
	// We optimistically remove the comment and all its direct children from cache.
	const deleteComment = useMutation({
		mutationFn: ({ comment }) => apiDeleteComment(comment.id),

		onMutate: async ({ comment }) => {
			const commentsKey = getCommentsKey()
			const cardKey = getCardKey()
			const boardKey = getBoardKey()

			await queryClient.cancelQueries({ queryKey: commentsKey })
			await queryClient.cancelQueries({ queryKey: cardKey })
			await queryClient.cancelQueries({ queryKey: boardKey })

			const previousComments = queryClient.getQueryData(commentsKey)
			const previousCard = queryClient.getQueryData(cardKey)
			const previousBoard = queryClient.getQueryData(boardKey)

			const current = Array.isArray(previousComments) ? previousComments : []
			// Remove the comment itself and any replies whose parentCommentId matches it
			const commentNumericId = Number(comment.id)
			const nextComments = current.filter(
				(c) => c.id !== comment.id && Number(c.parentCommentId) !== commentNumericId,
			)
			queryClient.setQueryData(commentsKey, nextComments)

			const removedCount = current.length - nextComments.length
			const newCount = Math.max(0, (previousCard?.commentCount ?? 0) - removedCount)
			patchCardDetailCommentCount(newCount)
			patchBoardCardCommentCount(newCount)

			return { previousComments, previousCard, previousBoard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousComments !== undefined) {
				queryClient.setQueryData(getCommentsKey(), context.previousComments)
			}
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCommentsKey() })
			queryClient.invalidateQueries({ queryKey: getCardKey() })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	return {
		comments,
		addComment,
		editComment,
		deleteComment,
	}
}

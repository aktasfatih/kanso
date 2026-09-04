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
	reactToComment as apiReactToComment,
	unreactToComment as apiUnreactToComment,
	resolveThread as apiResolveThread,
	unresolveThread as apiUnresolveThread,
} from '../services/api.js'
import { boardQueryKey } from './queryKeys.js'
import { getCurrentUser } from '@nextcloud/auth'

/**
 * The FIXED allowed reaction emoji set — must mirror the server's
 * CommentReactionService::ALLOWED_EMOJI. Deliberately small; this is NOT an
 * arbitrary emoji picker.
 * @type {string[]}
 */
export const REACTION_EMOJI = ['👍', '👎', '😄', '🎉', '❤️', '🚀', '👀']

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
	// Gated to a numeric card id: a card can be addressed by its human id in the
	// URL (e.g. /card/KAN-123, #3611), which the modal resolves + redirects to the
	// numeric id. Firing this against the raw ref would be a guaranteed-failing
	// GET /api/cards/KAN-123/comments before the redirect lands.
	const comments = useQuery({
		queryKey: computed(() => getCommentsKey()),
		queryFn: () => apiFetchComments(getCardId()),
		enabled: computed(() => /^\d+$/.test(getCardId())),
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

	// ── toggleReaction ──────────────────────────────────────────────────────────
	// Emoji reactions on a comment (#3550). db-first + optimistic: patch the
	// comment's `reactions` summary in the comments cache immediately, then
	// invalidate on settle so the server (authoritative counts + reactor names)
	// wins. `mine` on the summary drives whether a click reacts or unreacts.
	const currentUid = getCurrentUser()?.uid ?? ''

	function patchCommentReactions(commentId, emoji, adding) {
		const commentsKey = getCommentsKey()
		queryClient.setQueryData(commentsKey, (old) => {
			if (!Array.isArray(old)) return old
			return old.map((c) => {
				if (c.id !== commentId) return c
				const reactions = Array.isArray(c.reactions) ? c.reactions.slice() : []
				const idx = reactions.findIndex((r) => r.emoji === emoji)
				if (adding) {
					if (idx === -1) {
						reactions.push({ emoji, count: 1, mine: true, reactors: [] })
					} else if (!reactions[idx].mine) {
						reactions[idx] = { ...reactions[idx], count: reactions[idx].count + 1, mine: true }
					}
				} else if (idx !== -1) {
					const next = { ...reactions[idx], count: reactions[idx].count - 1, mine: false }
					if (next.count <= 0) {
						reactions.splice(idx, 1)
					} else {
						reactions[idx] = next
					}
				}
				return { ...c, reactions }
			})
		})
	}

	const toggleReaction = useMutation({
		mutationFn: ({ commentId, emoji, adding }) =>
			adding ? apiReactToComment(commentId, emoji) : apiUnreactToComment(commentId, emoji),

		onMutate: async ({ commentId, emoji, adding }) => {
			const commentsKey = getCommentsKey()
			await queryClient.cancelQueries({ queryKey: commentsKey })
			const previousComments = queryClient.getQueryData(commentsKey)
			patchCommentReactions(commentId, emoji, adding)
			return { previousComments }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousComments !== undefined) {
				queryClient.setQueryData(getCommentsKey(), context.previousComments)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCommentsKey() })
		},
	})

	/**
	 * Toggle the current user's reaction with `emoji` on a comment. Reads the
	 * comment's current summary to decide react vs. unreact.
	 * @param {object} comment the comment object (carries its `reactions` summary)
	 * @param {string} emoji one of REACTION_EMOJI
	 */
	function toggle(comment, emoji) {
		const summary = Array.isArray(comment.reactions)
			? comment.reactions.find((r) => r.emoji === emoji)
			: undefined
		const adding = !(summary && summary.mine)
		return toggleReaction.mutateAsync({ commentId: comment.id, emoji, adding })
	}

	// ── resolveThread ───────────────────────────────────────────────────────────
	// Mark a discussion thread resolved / reopen it. Same optimistic shape as
	// toggleReaction: patch the ONE top-level comment's `resolvedAt` in the
	// comments cache, roll back on error, invalidate on settle so the server's
	// timestamp wins. The collapsed rendering is derived from `resolvedAt` in the
	// component — nothing about collapse is cached or persisted here.
	const resolveThread = useMutation({
		mutationFn: ({ commentId, resolved }) =>
			resolved ? apiResolveThread(commentId) : apiUnresolveThread(commentId),

		onMutate: async ({ commentId, resolved }) => {
			const commentsKey = getCommentsKey()
			await queryClient.cancelQueries({ queryKey: commentsKey })
			const previousComments = queryClient.getQueryData(commentsKey)
			queryClient.setQueryData(commentsKey, (old) => {
				if (!Array.isArray(old)) return old
				return old.map((c) =>
					c.id === commentId
						? { ...c, resolvedAt: resolved ? Math.floor(Date.now() / 1000) : 0 }
						: c,
				)
			})
			return { previousComments }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousComments !== undefined) {
				queryClient.setQueryData(getCommentsKey(), context.previousComments)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCommentsKey() })
		},
	})

	/**
	 * Toggle the resolved state of a thread from its top-level comment.
	 * @param {object} comment the TOP-LEVEL comment of the thread
	 */
	function toggleResolved(comment) {
		return resolveThread.mutateAsync({
			commentId: comment.id,
			resolved: !(Number(comment.resolvedAt) > 0),
		})
	}

	return {
		comments,
		addComment,
		editComment,
		deleteComment,
		toggleReaction,
		toggleCommentReaction: toggle,
		resolveThread,
		toggleThreadResolved: toggleResolved,
		currentUid,
	}
}

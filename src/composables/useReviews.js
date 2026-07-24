// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQueryClient, useMutation } from '@tanstack/vue-query'
import {
	requestReview as apiRequestReview,
	withdrawReview as apiWithdrawReview,
	setReviewState as apiSetReviewState,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'

/**
 * Resolve a boardId argument that may be a plain value, a Vue ref (.value),
 * a computed ref (.value), or a plain getter function (e.g. () => props.boardId).
 */
function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

/** Urgency rank of a review aggregate state (higher = more attention needed). */
const REVIEW_URGENCY = { approved: 1, pending: 2, changes_requested: 3 }

/**
 * The more urgent of the current summary aggregate and an incoming state.
 *
 * The board SUMMARY payload carries only the scalar `reviewState` aggregate, not
 * the per-reviewer rows, so a correct aggregate cannot be recomputed on the
 * client. We therefore only ever optimistically UPGRADE the tile chip's urgency
 * (e.g. a new request → at least pending; a changes-requested verdict → red) and
 * never downgrade it — a downgrade would wrongly hide another reviewer's still-
 * outstanding state. onSettled invalidation + realtime refetch set the exact
 * aggregate a moment later.
 *
 * @param {string|null} current
 * @param {string} incoming
 * @returns {string}
 */
function moreUrgentState(current, incoming) {
	if (!current) return incoming
	return (REVIEW_URGENCY[incoming] ?? 0) > (REVIEW_URGENCY[current] ?? 0) ? incoming : current
}

/**
 * Review queries and mutations for a given board.
 *
 * Optimistic strategy:
 *   Mirrors useAssignees' onMutate EXACTLY — patch BOTH the board summary cache
 *   (via boardQueryKey, updating reviewState aggregate) and the ['card', String(cardId)]
 *   detail cache (updating reviews array); rollback both on error; invalidate both on settled.
 */
export function useReviews(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolveBoardId(boardId))
	}

	// ── Request a review from a user ────────────────────────────────────────────
	const requestReview = useMutation({
		mutationFn: ({ cardId, userId }) => apiRequestReview(cardId, userId),

		onMutate: async ({ cardId, userId }) => {
			const boardKey = getBoardKey()
			const cardKey = ['card', String(cardId)]
			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

			// Patch card detail: add a new pending review entry if not already present
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				const existing = Array.isArray(old.reviews) ? old.reviews : []
				if (existing.some((r) => r.reviewer === userId)) return old
				const newReview = {
					id: `optimistic-${userId}`,
					cardId,
					reviewer: userId,
					state: 'pending',
					requestedBy: null,
					createdAt: Math.floor(Date.now() / 1000),
				}
				const updated = [...existing, newReview]
				return { ...old, reviews: updated }
			})

			// Patch board summary: a request means the chip is at least "pending".
			// Upgrade-only (see moreUrgentState) — never downgrade from summary data.
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.map((c) =>
						c.id === cardId
							? { ...c, reviewState: moreUrgentState(c.reviewState, 'pending') }
							: c,
					),
				}
			})

			return { previousBoard, previousCard, cardKey }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
			if (context?.previousCard !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, context.previousCard)
			}
		},

		onSettled: (_data, _err, { cardId }) => {
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── Withdraw a review request ────────────────────────────────────────────────
	const withdrawReview = useMutation({
		mutationFn: ({ cardId, userId }) => apiWithdrawReview(cardId, userId),

		onMutate: async ({ cardId, userId }) => {
			const boardKey = getBoardKey()
			const cardKey = ['card', String(cardId)]
			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

			// Patch card detail: remove the review for this user
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				const updated = Array.isArray(old.reviews)
					? old.reviews.filter((r) => r.reviewer !== userId)
					: []
				return { ...old, reviews: updated }
			})

			// The summary aggregate can't be recomputed correctly on withdrawal
			// (other reviewers may remain), so the chip is left as-is; onSettled
			// invalidation + realtime refetch set the true aggregate.

			return { previousBoard, previousCard, cardKey }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
			if (context?.previousCard !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, context.previousCard)
			}
		},

		onSettled: (_data, _err, { cardId }) => {
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── Set review verdict (approve / request changes) ──────────────────────────
	const setReviewState = useMutation({
		mutationFn: ({ cardId, userId, state }) => apiSetReviewState(cardId, userId, state),

		onMutate: async ({ cardId, userId, state }) => {
			const boardKey = getBoardKey()
			const cardKey = ['card', String(cardId)]
			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

			// Patch card detail: update the reviewer's state
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				const updated = Array.isArray(old.reviews)
					? old.reviews.map((r) => r.reviewer === userId ? { ...r, state } : r)
					: []
				return { ...old, reviews: updated }
			})

			// Optimistically upgrade the chip only for the more-urgent verdict
			// (changes_requested). An approval can't be reflected from summary data
			// alone (other reviewers may still be pending), so leave it to refetch.
			if (state === 'changes_requested') {
				queryClient.setQueryData(boardKey, (old) => {
					if (!old) return old
					return {
						...old,
						cards: old.cards.map((c) =>
							c.id === cardId
								? { ...c, reviewState: moreUrgentState(c.reviewState, 'changes_requested') }
								: c,
						),
					}
				})
			}

			return { previousBoard, previousCard, cardKey }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
			if (context?.previousCard !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, context.previousCard)
			}
		},

		onSettled: (_data, _err, { cardId }) => {
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	return {
		requestReview,
		withdrawReview,
		setReviewState,
	}
}

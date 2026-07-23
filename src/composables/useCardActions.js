// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useCardActions — archive/unarchive and delete mutations for a single card.
 *
 * Optimistic strategy (mirrors useLabels.toggleLabel dual-cache pattern):
 *   1. Cancel in-flight board + card queries.
 *   2. Snapshot previous values for rollback on error.
 *   3. Patch the board cache (card.archived flag or card removal).
 *   4. Patch the card detail cache.
 *   5. On settled: invalidate both caches so server truth wins.
 *
 * The composable is kept thin: it wraps useMutation and accepts the resolved
 * boardId and cardId as plain values (or refs) so it can be used from both
 * CardModal and the future ArchivedPanel unarchive button without duplication.
 */

import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateCard as apiUpdateCard, deleteCard as apiDeleteCard } from '../services/api.js'
import { boardQueryKey } from './queryKeys.js'

/**
 * Resolve a value that may be a plain primitive, a Vue ref, or a getter fn.
 * @param {any} v
 */
function resolve(v) {
	if (typeof v === 'function') return v()
	if (v !== null && typeof v === 'object' && 'value' in v) return v.value
	return v
}

/**
 * @param {import('vue').Ref<string>|string} boardId
 * @param {import('vue').Ref<string>|string} cardId
 */
export function useCardActions(boardId, cardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolve(boardId))
	}

	function getCardKey() {
		return ['card', String(resolve(cardId))]
	}

	// ── Archive / Unarchive ─────────────────────────────────────────────────────
	const setArchived = useMutation({
		mutationFn: ({ archived }) =>
			apiUpdateCard(resolve(cardId), { archived }),

		onMutate: async ({ archived }) => {
			const boardKey = getBoardKey()
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

			// Patch board summary cache — flip the card's archived flag
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.map((c) =>
						c.id === resolve(cardId) ? { ...c, archived } : c,
					),
				}
			})

			// Patch detail cache
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				return { ...old, archived }
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

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCardKey() })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── Delete ──────────────────────────────────────────────────────────────────
	const deleteCard = useMutation({
		mutationFn: () => apiDeleteCard(resolve(cardId)),

		onMutate: async () => {
			const boardKey = getBoardKey()
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousBoard = queryClient.getQueryData(boardKey)

			// Remove the card from the board summary cache
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.filter((c) => c.id !== resolve(cardId)),
				}
			})

			// Remove the detail cache entry
			queryClient.removeQueries({ queryKey: cardKey })

			return { previousBoard, cardKey }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
		},

		onSettled: () => {
			// Board is the only remaining relevant cache; card detail was removed.
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	return {
		setArchived,
		deleteCard,
	}
}

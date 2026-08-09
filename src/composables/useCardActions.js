// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useCardActions - archive/unarchive and delete mutations for a single card.
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
 * CardModal and the archived-cards view's unarchive button without duplication.
 */

import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateCard as apiUpdateCard, deleteCard as apiDeleteCard, restoreCard as apiRestoreCard } from '../services/api.js'
import { boardQueryKey, invalidateMyWork } from './queryKeys.js'

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

			// Patch board summary cache - flip the card's archived flag. Board card
			// ids are numeric; resolve(cardId) is the string route param, so coerce
			// (matching usePriority/useChecklist) or the match never fires.
			const numericCardId = Number(resolve(cardId))
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.map((c) =>
						c.id === numericCardId ? { ...c, archived } : c,
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
			// Archiving removes the card from the My Work feeds (#3766).
			invalidateMyWork(queryClient)
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

			// Remove the card from the board summary cache (numeric id vs the string
			// route param — coerce or the filter removes nothing).
			const numericCardId = Number(resolve(cardId))
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.filter((c) => c.id !== numericCardId),
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
			// Deleting removes the card from the My Work feeds (#3766).
			invalidateMyWork(queryClient)
		},
	})

	// ── Restore (undo delete) ───────────────────────────────────────────────────
	// Note: restore does NOT re-attach sub-cards that were detached when the card
	// was deleted - this is documented self-healing behaviour.
	const restoreCard = useMutation({
		mutationFn: () => apiRestoreCard(resolve(cardId)),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
			// Restoring can return the card to the My Work feeds (#3766).
			invalidateMyWork(queryClient)
		},
	})

	return {
		setArchived,
		deleteCard,
		restoreCard,
	}
}

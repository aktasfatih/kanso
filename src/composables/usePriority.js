// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * usePriority — optimistic priority update mutation for a single card.
 *
 * Mirrors the dual-cache pattern from useCardActions:
 *   1. Cancel in-flight board + card queries.
 *   2. Snapshot previous values for rollback.
 *   3. Patch the board summary cache (card.priority field).
 *   4. Patch the card detail cache.
 *   5. On settled: invalidate both caches so server truth wins.
 *
 * Priority levels: 0=none, 1=low, 2=medium, 3=high, 4=urgent.
 */

import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateCard as apiUpdateCard } from '../services/api.js'
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

/** Priority level metadata — used in both CardModal and CardTile. */
export const PRIORITY_LEVELS = [
	{ value: 0, label: 'None', shortLabel: '' },
	{ value: 1, label: 'Low', shortLabel: 'Low' },
	{ value: 2, label: 'Medium', shortLabel: 'Med' },
	{ value: 3, label: 'High', shortLabel: 'High' },
	{ value: 4, label: 'Urgent', shortLabel: 'Urgent' },
]

/**
 * @param {import('vue').Ref<string>|string} boardId
 * @param {import('vue').Ref<string>|string} cardId
 */
export function usePriority(boardId, cardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolve(boardId))
	}

	function getCardKey() {
		return ['card', String(resolve(cardId))]
	}

	const setPriority = useMutation({
		mutationFn: ({ priority }) =>
			apiUpdateCard(resolve(cardId), { priority }),

		onMutate: async ({ priority }) => {
			const boardKey = getBoardKey()
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

			// Patch board summary cache — update the card's priority. Board card
			// ids are numbers; the resolved cardId is the string route param, so
			// coerce (a raw === would never match and the tile wouldn't update
			// optimistically — matches useChecklist's Number() comparison).
			const numericCardId = Number(resolve(cardId))
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.map((c) =>
						c.id === numericCardId ? { ...c, priority } : c,
					),
				}
			})

			// Patch card detail cache
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				return { ...old, priority }
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

	return { setPriority }
}

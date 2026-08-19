// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useCardType - optimistic card-type update mutation for a single card (#3402).
 *
 * A card has exactly ONE built-in type (icon-first, lighter than a label). The
 * built-in set is fixed - '' (none), bug, feature, task, chore - there is no
 * custom-type editor. Mirrors usePriority's dual-cache optimistic pattern:
 *   1. Cancel in-flight board + card queries.
 *   2. Snapshot previous values for rollback.
 *   3. Patch the board summary cache (card.type field).
 *   4. Patch the card detail cache.
 *   5. On settled: invalidate both caches so server truth wins.
 */

import { translate as t } from '@nextcloud/l10n'
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

/**
 * Built-in card types - used in CardModal (picker), CardTile (icon) and
 * BoardFilterBar (facet). `value` is the wire value ('' = none). The icon is
 * resolved by the consuming component (keeps this composable icon-free).
 */
export const CARD_TYPES = [
	{ value: 'bug', label: t('kanso', 'Bug'), color: 'e74c3c' },
	{ value: 'feature', label: t('kanso', 'Feature'), color: '2ecc71' },
	{ value: 'task', label: t('kanso', 'Task'), color: '3498db' },
	{ value: 'chore', label: t('kanso', 'Chore'), color: '95a5a6' },
]

/**
 * @param {import('vue').Ref<string>|string} boardId
 * @param {import('vue').Ref<string>|string} cardId
 */
export function useCardType(boardId, cardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolve(boardId))
	}

	function getCardKey() {
		return ['card', String(resolve(cardId))]
	}

	const setType = useMutation({
		mutationFn: ({ type }) =>
			apiUpdateCard(resolve(cardId), { type }),

		onMutate: async ({ type }) => {
			const boardKey = getBoardKey()
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

			// Patch board summary cache - board card ids are numbers; the resolved
			// cardId is the string route param, so coerce (matches usePriority).
			const numericCardId = Number(resolve(cardId))
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.map((c) =>
						c.id === numericCardId ? { ...c, type } : c,
					),
				}
			})

			// Patch card detail cache
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				return { ...old, type }
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

	return { setType }
}

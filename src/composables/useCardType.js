// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useCardType - optimistic card-type update mutation for a single card (#3402).
 *
 * A card has exactly ONE built-in type (icon-first, lighter than a label). The
 * built-in set is fixed - '' (none), bug, feature, task, chore - there is no
 * custom-type editor. Mirrors usePriority's dual-cache optimistic pattern:
 *   1. Cancel in-flight board + card queries.
 *   2. Snapshot the card's PREVIOUS `type` - that one field, in both caches, not
 *      the whole cached objects - so a failure can put it back without
 *      disturbing anything else that changed meanwhile (#10927, the same
 *      narrowing useLabels/useAssignees got in #10923).
 *   3. Patch the board summary cache (card.type field).
 *   4. Patch the card detail cache.
 *   5. On settled: invalidate both caches so server truth wins.
 */

import { translate as t } from '@nextcloud/l10n'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateCard as apiUpdateCard } from '../services/api.js'
import { boardQueryKey, invalidateCrossBoardFeeds } from './queryKeys.js'

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

			// Board card ids are numbers; the resolved cardId is the string route
			// param, so coerce (matches usePriority). Resolved once here so the
			// rollback addresses the same card the patch did.
			const numericCardId = Number(resolve(cardId))

			// Snapshot ONLY `type`, not the whole cached objects (#10927). The
			// attribute pickers in the card modal save independently, so a type
			// write and a label, assignee or project write are genuinely in flight
			// at once - and re-setting a whole card object on failure erases
			// whatever the other picker committed in between, which the server has
			// kept, leaving the client lying until something reads the card again.
			// The board snapshot was wider still: a whole-board restore rewinds
			// every OTHER card in the cache too, including a title a realtime delta
			// changed mid-flight. `undefined` here means "no such cache entry",
			// i.e. nothing to undo; an entry with no usable type is snapshotted as
			// the '' ("none") the UI already renders it as.
			const snapshotType = (card) => card
				? (typeof card.type === 'string' ? card.type : '')
				: undefined
			const previousDetailType = snapshotType(queryClient.getQueryData(cardKey))
			const previousSummaryType = snapshotType(
				queryClient.getQueryData(boardKey)?.cards?.find((c) => c.id === numericCardId),
			)

			// Patch board summary cache
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

			return { previousDetailType, previousSummaryType, cardKey, numericCardId }
		},

		onError: (_err, _vars, context) => {
			// Put `type` back, and ONLY `type` - layered onto whatever the cache
			// holds at rollback time (the functional updater form) rather than over
			// the top of it. See the snapshot comment in onMutate.
			if (context?.previousSummaryType !== undefined) {
				queryClient.setQueryData(getBoardKey(), (old) => {
					if (!old) return old
					return {
						...old,
						cards: old.cards.map((c) => (c.id === context.numericCardId
							? { ...c, type: context.previousSummaryType }
							: c)),
					}
				})
			}
			if (context?.previousDetailType !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, (old) => {
					if (!old) return old
					return { ...old, type: context.previousDetailType }
				})
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCardKey() })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
			// Type is a View group-by dimension and filter facet, and it is set from
			// the same card detail the View opens as an overlay - so the feed needs
			// the settle invalidation even though type is not a My Work membership
			// criterion (#9859).
			invalidateCrossBoardFeeds(queryClient)
		},
	})

	return { setType }
}

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * usePriority - optimistic priority update mutation for a single card.
 *
 * Mirrors the dual-cache pattern from useCardActions:
 *   1. Cancel in-flight board + card queries.
 *   2. Snapshot the card's PREVIOUS `priority` - that one field, in both caches,
 *      not the whole cached objects - so a failure can put it back without
 *      disturbing anything else that changed meanwhile (#10927, the same
 *      narrowing useLabels/useAssignees got in #10923).
 *   3. Patch the board summary cache (card.priority field).
 *   4. Patch the card detail cache.
 *   5. On settled: invalidate both caches so server truth wins.
 *
 * Priority levels: 0=none, 1=low, 2=medium, 3=high, 4=urgent.
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

/** Priority level metadata - used in both CardModal and CardTile. */
export const PRIORITY_LEVELS = [
	{ value: 0, label: t('kanso', 'None'), shortLabel: '' },
	{ value: 1, label: t('kanso', 'Low'), shortLabel: t('kanso', 'Low') },
	{ value: 2, label: t('kanso', 'Medium'), shortLabel: t('kanso', 'Med') },
	{ value: 3, label: t('kanso', 'High'), shortLabel: t('kanso', 'High') },
	{ value: 4, label: t('kanso', 'Urgent'), shortLabel: t('kanso', 'Urgent') },
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

			// Board card ids are numbers; the resolved cardId is the string route
			// param, so coerce (a raw === would never match and the tile wouldn't
			// update optimistically - matches useChecklist's Number() comparison).
			// Resolved once here so the rollback addresses the same card the patch
			// did, whatever the route has moved on to by then.
			const numericCardId = Number(resolve(cardId))

			// Snapshot ONLY `priority`, not the whole cached objects (#10927). The
			// attribute pickers in the card modal save independently, so a priority
			// write and a label, assignee or project write are genuinely in flight
			// at once - and re-setting a whole card object on failure erases
			// whatever the other picker committed in between, which the server has
			// kept, leaving the client lying until something reads the card again.
			// The board snapshot was wider still: a whole-board restore rewinds
			// every OTHER card in the cache too, including a title a realtime delta
			// changed mid-flight. `undefined` here means "no such cache entry",
			// i.e. nothing to undo; an entry with no usable priority is
			// snapshotted as the 0 ("none") the UI already renders it as.
			const snapshotPriority = (card) => card
				? (Number.isFinite(card.priority) ? card.priority : 0)
				: undefined
			const previousDetailPriority = snapshotPriority(queryClient.getQueryData(cardKey))
			const previousSummaryPriority = snapshotPriority(
				queryClient.getQueryData(boardKey)?.cards?.find((c) => c.id === numericCardId),
			)

			// Patch board summary cache - update the card's priority.
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

			return { previousDetailPriority, previousSummaryPriority, cardKey, numericCardId }
		},

		onError: (_err, _vars, context) => {
			// Put `priority` back, and ONLY `priority` - layered onto whatever the
			// cache holds at rollback time (the functional updater form) rather
			// than over the top of it. See the snapshot comment in onMutate.
			if (context?.previousSummaryPriority !== undefined) {
				queryClient.setQueryData(getBoardKey(), (old) => {
					if (!old) return old
					return {
						...old,
						cards: old.cards.map((c) => (c.id === context.numericCardId
							? { ...c, priority: context.previousSummaryPriority }
							: c)),
					}
				})
			}
			if (context?.previousDetailPriority !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, (old) => {
					if (!old) return old
					return { ...old, priority: context.previousDetailPriority }
				})
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCardKey() })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
			// Priority is a View group-by dimension and filter facet, and it is set
			// from the same card detail the View opens as an overlay - so the feed
			// needs the settle invalidation even though priority is not a My Work
			// membership criterion (#9859).
			invalidateCrossBoardFeeds(queryClient)
		},
	})

	return { setPriority }
}

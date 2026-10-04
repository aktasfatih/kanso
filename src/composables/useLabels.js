// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQueryClient, useMutation } from '@tanstack/vue-query'
import {
	createLabel as apiCreateLabel,
	updateLabel as apiUpdateLabel,
	deleteLabel as apiDeleteLabel,
	assignLabel as apiAssignLabel,
	unassignLabel as apiUnassignLabel,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'
import { invalidateCrossBoardFeeds } from './queryKeys.js'
import { createToggleQueue } from './useToggleQueue.js'

/**
 * Label mutations for a given board.
 *
 * Optimistic strategy for toggleLabel (assign / unassign):
 *   1. Cancel in-flight board queries to avoid clobbering the patch.
 *   2. setQueryData - patch ONLY the target card's labelIds array (add or remove).
 *      The functional updater form is used so the patch always operates on the
 *      latest snapshot in the cache (same pattern as applyOptimisticPatch in
 *      useCardMove).
 *   3. Also snapshot the previous board cache value so we can roll back by
 *      re-setting it on error.
 *   4. On settled: invalidate ['card', cardId] (detail query), the board query
 *      and the cross-board feeds so the UI reconciles with server truth.
 *
 * Toggles are SERIALISED through enqueueToggle, not fired in parallel and not
 * dropped - see useToggleQueue.js (shared with the assignee picker) for why that
 * distinction is the whole point (#10920).
 *
 * For create / update / delete label we do NOT do optimistic patches because
 * these are low-frequency settings-panel actions; invalidating on settled is
 * sufficient and keeps the code simple (PM over-engineering guard).
 */
/**
 * Resolve a boardId argument that may be a plain value, a Vue ref (.value),
 * a computed ref (.value), or a plain getter function (e.g. () => props.boardId).
 */
function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

export function useLabels(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolveBoardId(boardId))
	}

	// ── Create label ────────────────────────────────────────────────────────
	const createLabel = useMutation({
		mutationFn: ({ title, color }) =>
			apiCreateLabel({
				boardId: resolveBoardId(boardId),
				title,
				color: color || null,
			}),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── Update label ────────────────────────────────────────────────────────
	const updateLabel = useMutation({
		mutationFn: ({ labelId, title, color }) =>
			apiUpdateLabel(labelId, {
				...(title !== undefined ? { title } : {}),
				// Pass "" to clear color (backend spec: "" → clear)
				...(color !== undefined ? { color } : {}),
			}),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── Delete label ────────────────────────────────────────────────────────
	const deleteLabel = useMutation({
		mutationFn: ({ labelId }) => apiDeleteLabel(labelId),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── Toggle label on a card (assign / unassign) ──────────────────────────
	// assign = true → assign, assign = false → unassign
	const toggleLabel = useMutation({
		mutationFn: ({ cardId, labelId, assign }) =>
			assign ? apiAssignLabel(cardId, labelId) : apiUnassignLabel(cardId, labelId),

		onMutate: async ({ cardId, labelId, assign }) => {
			// Cancel in-flight queries so they don't overwrite the patches
			const boardKey = getBoardKey()
			const cardKey = ['card', String(cardId)]
			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			// Snapshot previous state for potential rollback
			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

			const patchIds = (ids) => assign
				? (ids.includes(labelId) ? ids : [...ids, labelId])
				: ids.filter((id) => id !== labelId)

			// Optimistically patch the card's labelIds in the board summary cache
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.map((c) => {
						if (c.id !== cardId) return c
						return { ...c, labelIds: patchIds(Array.isArray(c.labelIds) ? c.labelIds : []) }
					}),
				}
			})

			// ...and in the detail cache - the modal's chips read from here, so
			// without this patch they would lag until the settle invalidation.
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				return { ...old, labelIds: patchIds(Array.isArray(old.labelIds) ? old.labelIds : []) }
			})

			return { previousBoard, previousCard, cardKey }
		},

		onError: (_err, _vars, context) => {
			// Roll back to the snapshots taken before the optimistic patches
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
			if (context?.previousCard !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, context.previousCard)
			}
		},

		onSettled: (_data, _err, { cardId }) => {
			// Sync card detail query and board query with server truth
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
			// A label is both a View filter dimension and a chip the View renders,
			// and the card detail opens as an overlay ON the View - so without this
			// a toggle never reaches the feed (#9859).
			invalidateCrossBoardFeeds(queryClient)
		},
	})

	// ── Serialising the picker's picks (#10920) ─────────────────────────────────
	// One pick at a time, queued rather than dropped, keyed per (card, label).
	// The card modal used to hold a single `labelTogglePending` label id for the
	// whole picker and return early while it was set, so a label picked during
	// the previous write's round trip sent no request at all - measured in the
	// browser at 1 of 3 picks reaching the server with the label write delayed
	// 800ms, and 2 of 3 with no added latency, silently either way. The mechanism
	// lives in useToggleQueue.js, shared with the assignee picker, which had the
	// identical defect (#10799).
	const { enqueue, isPending } = createToggleQueue({
		mutate: (vars) => toggleLabel.mutateAsync(vars),
		keyOf: ({ cardId, labelId }) => `${cardId}:${labelId}`,
	})

	/**
	 * Queue one label assign/unassign behind whatever is already running.
	 *
	 * @param {{cardId: number, labelId: number, assign: boolean}} vars
	 * @return {Promise<*>} this pick's own outcome - resolves with the mutation
	 *   result, with TOGGLE_ALREADY_PENDING when it was a same-row
	 *   double-submit, or rejects with this pick's error.
	 */
	function enqueueToggle({ cardId, labelId, assign }) {
		return enqueue({ cardId, labelId, assign })
	}

	/**
	 * Whether this (card, label) toggle is on the wire or waiting its turn.
	 * Reactive - a template reading it re-renders when a toggle starts or ends.
	 *
	 * @param {number|string} cardId
	 * @param {number} labelId
	 * @return {boolean}
	 */
	function isTogglePending(cardId, labelId) {
		return isPending({ cardId, labelId })
	}

	// `toggleLabel` itself is deliberately NOT returned: a second, un-queued
	// handle to the same mutation is exactly how a pick would get past the queue,
	// and a guard a caller can opt out of in one destructure is not a guard.
	// enqueueToggle is the only way to toggle a label on a card.
	return {
		createLabel,
		updateLabel,
		deleteLabel,
		enqueueToggle,
		isTogglePending,
	}
}

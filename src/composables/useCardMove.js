// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref } from 'vue'
import { useQueryClient } from '@tanstack/vue-query'
import { moveCard } from '../services/api.js'
import { translate as t } from '@nextcloud/l10n'
import { boardQueryKey } from './useBoard.js'

/**
 * Per-board FIFO move queue with optimistic cache updates.
 *
 * Queue drain strategy:
 * - Each enqueue chains onto the previous server call (FIFO).
 * - On success: reconcile ONLY {stackId, sortKey, lastModified} from response into summary cache
 *   (never splice the full card — it has description which isn't in summary cache).
 * - On failure / 409: rollback is DEFERRED to the drain — an invalidate fired
 *   mid-queue would refetch pre-move server state and clobber newer optimistic
 *   patches (visible jump-back).
 * - No invalidate ever runs while pendingCount > 0. When the queue drains, one
 *   final invalidate syncs rollbacks and any server-side divergence; a new
 *   enqueue cancels that refetch via cancelQueries before patching.
 *
 * This survives: drag A then immediately drag B → both succeed → final cache
 * matches server because each success reconciles that card, and the drain
 * invalidate catches any edge-case divergence.
 */
// Module-level registry of boards with in-flight or queued moves, keyed by
// String(boardId). Realtime consumers (push invalidation in main.js, the
// poll interval in useBoard) check this to never refetch mid-drag — a
// refetch would clobber optimistic patches with pre-move server state.
const pendingByBoard = new Map()

function pendingKeyOf(boardId) {
	const value = typeof boardId === 'object' && boardId !== null && boardId.value !== undefined ? boardId.value : boardId
	return String(value)
}

/**
 * Whether the board has moves that have not reached the server yet.
 *
 * @param {number|string|import('vue').Ref} boardId
 * @return {boolean}
 */
export function isBoardMovePending(boardId) {
	return (pendingByBoard.get(pendingKeyOf(boardId)) ?? 0) > 0
}

export function useCardMove(boardId) {
	const queryClient = useQueryClient()
	const lastError = ref(null)

	// pendingCount tracks in-flight + queued moves
	let pendingCount = 0
	// The FIFO promise chain — each enqueue appends to this
	let queue = Promise.resolve()

	function getBoardQueryKey() {
		return boardQueryKey(boardId)
	}

	function applyOptimisticPatch(cardId, stackId, sortKey) {
		const key = getBoardQueryKey()
		queryClient.setQueryData(key, (old) => {
			if (!old) return old
			return {
				...old,
				cards: old.cards.map((c) =>
					c.id === cardId ? { ...c, stackId, sortKey } : c,
				),
			}
		})
	}

	function reconcileFromServer(updatedCard) {
		const key = getBoardQueryKey()
		queryClient.setQueryData(key, (old) => {
			if (!old) return old
			return {
				...old,
				cards: old.cards.map((c) =>
					c.id === updatedCard.id
						? { ...c, stackId: updatedCard.stackId, sortKey: updatedCard.sortKey, lastModified: updatedCard.lastModified }
						: c,
				),
			}
		})
	}

	function enqueueMove({ cardId, targetStackId, afterCardId, optimisticKey }) {
		// Cancel any in-flight board queries so they don't clobber the optimistic patch
		queryClient.cancelQueries({ queryKey: getBoardQueryKey() })

		// Apply optimistic patch synchronously
		applyOptimisticPatch(cardId, targetStackId, optimisticKey)

		// Capture the key now — boardId may be a ref that changes on navigation
		const pendingKey = pendingKeyOf(boardId)
		pendingCount++
		pendingByBoard.set(pendingKey, (pendingByBoard.get(pendingKey) ?? 0) + 1)

		queue = queue.then(async () => {
			try {
				const updated = await moveCard(cardId, {
					targetStackId,
					afterCardId: afterCardId ?? null,
				})
				reconcileFromServer(updated)
			} catch (err) {
				// 409 = rebalance_required; anything else is a generic error
				const status = err?.response?.status
				const serverError = err?.response?.data?.error
				if (status === 409 && serverError === 'rebalance_required') {
					lastError.value = t('kanso', 'Board ordering needs a refresh.')
				} else {
					lastError.value = t('kanso', 'Failed to move card. Please try again.')
				}
				// Rollback happens at drain time — invalidating here would
				// refetch pre-move state over newer optimistic patches.
			} finally {
				pendingCount--
				const remaining = (pendingByBoard.get(pendingKey) ?? 1) - 1
				if (remaining > 0) {
					pendingByBoard.set(pendingKey, remaining)
				} else {
					pendingByBoard.delete(pendingKey)
				}
				if (pendingCount === 0) {
					// Queue drained — one sync covers rollbacks and divergence.
					queryClient.invalidateQueries({ queryKey: getBoardQueryKey() })
				}
			}
		})
	}

	function dismissError() {
		lastError.value = null
	}

	return { enqueueMove, lastError, dismissError }
}

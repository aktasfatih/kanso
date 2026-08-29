// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref } from 'vue'
import { useQueryClient } from '@tanstack/vue-query'
import { moveCard } from '../services/api.js'
import { translate as t } from '@nextcloud/l10n'
import { boardQueryKey } from './queryKeys.js'

/**
 * Per-board FIFO move queue with optimistic cache updates.
 *
 * Queue drain strategy:
 * - Each enqueue chains onto the previous server call (FIFO).
 * - On success: reconcile ONLY {stackId, sortKey, lastModified} from response into summary cache
 *   (never splice the full card - it has description which isn't in summary cache).
 * - On failure / 409: rollback is DEFERRED to the drain - an invalidate fired
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
// poll interval in useBoard) check this to never refetch mid-drag - a
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
	// The FIFO promise chain - each enqueue appends to this
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

	/**
	 * Snapshot a single card's pre-move placement so a failed move can be
	 * reverted per-card without clobbering other in-flight optimistic patches
	 * (a full-board restore would undo newer queued moves). Mirrors the
	 * snapshot-in-onMutate → restore-in-onError pattern, scoped to one row.
	 *
	 * @param {number|string} cardId
	 * @return {?{stackId: *, sortKey: *}}
	 */
	function snapshotCard(cardId) {
		const old = queryClient.getQueryData(getBoardQueryKey())
		const card = old?.cards?.find((c) => c.id === cardId)
		return card ? { stackId: card.stackId, sortKey: card.sortKey } : null
	}

	function restoreCard(cardId, snapshot) {
		if (!snapshot) return
		const key = getBoardQueryKey()
		queryClient.setQueryData(key, (old) => {
			if (!old) return old
			return {
				...old,
				cards: old.cards.map((c) =>
					c.id === cardId
						? { ...c, stackId: snapshot.stackId, sortKey: snapshot.sortKey }
						: c,
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

		// Snapshot this card's placement BEFORE patching so a failed move can be
		// reverted immediately (per-card, not a full-board restore that would
		// clobber other queued optimistic patches).
		const cardSnapshot = snapshotCard(cardId)

		// Apply optimistic patch synchronously
		applyOptimisticPatch(cardId, targetStackId, optimisticKey)

		// Capture the key now - boardId may be a ref that changes on navigation
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
				// A move that lands retires the previous move's banner (#10008).
				// Identical defect to the keyboard shortcuts: lastError was only
				// ever cleared by the manual ×, so one failed drag left the board
				// claiming failure through every later successful drag.
				lastError.value = null
			} catch (err) {
				// 409 = rebalance_required; 403 = review gate; anything else is a generic error
				const status = err?.response?.status
				const serverError = err?.response?.data?.error
				if (status === 409 && serverError === 'rebalance_required') {
					lastError.value = t('kanso', 'Board ordering needs a refresh.')
				} else if (status === 403 && serverError) {
					lastError.value = serverError
				} else {
					lastError.value = t('kanso', 'Failed to move card. Please try again.')
				}
				// Revert THIS card to its pre-move placement right away using the
				// snapshot. Scoped to one row so it never clobbers other queued
				// optimistic patches; a full invalidate here would refetch
				// pre-move state over newer moves. The drain invalidate below
				// still reconciles any remaining server-side divergence.
				restoreCard(cardId, cardSnapshot)
			} finally {
				pendingCount--
				const remaining = (pendingByBoard.get(pendingKey) ?? 1) - 1
				if (remaining > 0) {
					pendingByBoard.set(pendingKey, remaining)
				} else {
					pendingByBoard.delete(pendingKey)
				}
				if (pendingCount === 0) {
					// Queue drained - one sync covers rollbacks and divergence.
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

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, computed } from 'vue'
import { bulkApplyCards } from '../services/api.js'
import { boardQueryKey, invalidateCrossBoardFeeds } from './queryKeys.js'

/**
 * Cards per /api/cards/bulk request. Mirrors BulkCardService::MAX_CARDS, which
 * is pinned to this number by BulkCardServiceTest — a longer list is a 400.
 */
const MAX_PER_REQUEST = 100

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
 * useBulkSelect - reactive selection store + bulk apply for multi-select mode.
 *
 * @param {import('vue').Ref<string>|string|Function} boardId
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 */
export function useBulkSelect(boardId, queryClient) {
	/** Whether multi-select mode is active. */
	const selectionMode = ref(false)

	/** Set of selected numeric card ids. Use a new Set each mutation for Vue reactivity. */
	const selected = ref(new Set())

	/** Last selected card id - used for shift-range selection. */
	const lastSelectedId = ref(null)

	/** Number of currently selected cards. */
	const selectedCount = computed(() => selected.value.size)

	/** Whether a given card id is in the selection. */
	function isSelected(id) {
		return selected.value.has(Number(id))
	}

	/** Toggle a card in/out of the selection (creates a new Set for reactivity). */
	function toggle(id) {
		const numId = Number(id)
		const s = new Set(selected.value)
		if (s.has(numId)) {
			s.delete(numId)
		} else {
			s.add(numId)
		}
		selected.value = s
		lastSelectedId.value = numId
	}

	/**
	 * Shift-range select: given the flat ordered list of currently-visible card ids
	 * and a clicked id, add everything between lastSelectedId and id (inclusive) to
	 * the selection. If no lastSelectedId, falls back to toggle.
	 *
	 * @param {number[]} orderedIds - flat ordered visible card ids
	 * @param {number} id - the clicked card id
	 */
	function selectRange(orderedIds, id) {
		const numId = Number(id)
		if (lastSelectedId.value == null) {
			toggle(numId)
			return
		}
		const startIdx = orderedIds.indexOf(lastSelectedId.value)
		const endIdx = orderedIds.indexOf(numId)
		if (startIdx === -1 || endIdx === -1) {
			toggle(numId)
			return
		}
		const lo = Math.min(startIdx, endIdx)
		const hi = Math.max(startIdx, endIdx)
		const s = new Set(selected.value)
		for (let i = lo; i <= hi; i++) {
			s.add(Number(orderedIds[i]))
		}
		selected.value = s
		lastSelectedId.value = numId
	}

	/** Clear the entire selection. */
	function clear() {
		selected.value = new Set()
		lastSelectedId.value = null
	}

	/** Enter multi-select mode. */
	function enterMode() {
		selectionMode.value = true
	}

	/** Exit multi-select mode and clear selection. */
	function exitMode() {
		clear()
		selectionMode.value = false
	}

	/** Whether a bulk action is in flight. */
	const applying = ref(false)

	/** Result of the last successful bulk apply. */
	const lastResult = ref(null)

	/**
	 * Apply a bulk action to an explicit list of card ids, WITHOUT touching the
	 * selection or selection mode — the column-level actions (#10430) run over a
	 * whole column, not over a selection, so they must not clear one.
	 *
	 * The list is chunked to the server's MAX_CARDS cap (#10435: a single
	 * oversized list is a hard 400, and a shift-range has no upper limit, so any
	 * column with more than 100 cards could otherwise fail every bulk action
	 * wholesale). The chunks are issued SEQUENTIALLY — each card's update fires
	 * its own board-changed push, so fanning them out in parallel would only pile
	 * more concurrent work on the same board.
	 *
	 * A chunk that rejects does NOT discard the chunks that already committed:
	 * the partial summary is attached to the thrown error as `err.partial` so the
	 * caller can still report — or offer an undo over — the cards that really did
	 * change, and the cache invalidation runs either way (those writes happened
	 * server-side regardless).
	 *
	 * @param {number[]} cardIds - card ids to apply the action to
	 * @param {string} action - one of: move, add_label, remove_label, assign_user, set_due_date, set_status, archive, unarchive, delete
	 * @param {object} params - action-specific params
	 * @return {Promise<{ok: number[], skipped: object[]}>} merged per-card summary
	 * @throws on server error, with the partial summary on `err.partial`
	 */
	async function applyToIds(cardIds, action, params = {}) {
		const summary = { ok: [], skipped: [] }
		try {
			for (let i = 0; i < cardIds.length; i += MAX_PER_REQUEST) {
				const chunk = cardIds.slice(i, i + MAX_PER_REQUEST)
				const result = await bulkApplyCards(chunk, action, params)
				summary.ok.push(...(result?.ok ?? []))
				summary.skipped.push(...(result?.skipped ?? []))
			}
		} catch (err) {
			err.partial = summary
			throw err
		} finally {
			await queryClient.invalidateQueries({ queryKey: boardQueryKey(resolve(boardId)) })
			// Bulk assign/archive/delete/move can change My Work membership (#3766, #9859).
			invalidateCrossBoardFeeds(queryClient)
		}
		return summary
	}

	/**
	 * Apply a bulk action to the current selection, then clear it.
	 *
	 * @param {string} action - one of: move, add_label, remove_label, assign_user, set_due_date, set_status, archive, unarchive, delete
	 * @param {object} params - action-specific params
	 * @throws on server error
	 */
	async function apply(action, params = {}) {
		applying.value = true
		try {
			const result = await applyToIds([...selected.value], action, params)
			lastResult.value = result
			clear()
			return result
		} finally {
			applying.value = false
		}
	}

	return {
		selectionMode,
		selected,
		selectedCount,
		lastSelectedId,
		isSelected,
		toggle,
		selectRange,
		clear,
		enterMode,
		exitMode,
		applying,
		lastResult,
		apply,
		applyToIds,
	}
}

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ref, computed } from 'vue'
import { bulkApplyCards } from '../services/api.js'
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
	 * Apply a bulk action to the current selection.
	 *
	 * @param {string} action - one of: move, add_label, remove_label, assign_user, set_due_date, archive, delete
	 * @param {object} params - action-specific params
	 * @throws on server error
	 */
	async function apply(action, params = {}) {
		applying.value = true
		try {
			const result = await bulkApplyCards([...selected.value], action, params)
			lastResult.value = result
			await queryClient.invalidateQueries({ queryKey: boardQueryKey(resolve(boardId)) })
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
	}
}

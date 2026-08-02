// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useChecklist - checklist query and mutations for a single card.
 *
 * Optimistic strategy mirrors useLabels / useCardActions (dual-cache pattern):
 *   1. Cancel in-flight queries for both the checklist key and the card detail key.
 *   2. Snapshot previous values for rollback on error.
 *   3. Optimistically patch the checklist cache AND the card detail cache
 *      (checklistItems + checklist summary counts).
 *   4. ALSO patch the board cache's per-card checklist summary so the tile badge
 *      updates immediately without waiting for the settle invalidation.
 *   5. On settled: invalidate the card detail query AND the board query so server
 *      truth eventually wins.
 *
 * Items are sorted by sortKey using plain codepoint string comparison (< / >),
 * which matches the app's lexorank-style keys. Never use localeCompare here.
 *
 * The composable accepts a cardId that may be:
 *   - a plain number/string
 *   - a Vue ref
 *   - a getter function (e.g. () => props.cardId)
 * …and a boardId with the same flexibility (needed to keep the board tile badge in
 * sync optimistically).
 */

import { computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchChecklist as apiFetchChecklist,
	createChecklistItem as apiCreateChecklistItem,
	updateChecklistItem as apiUpdateChecklistItem,
	moveChecklistItem as apiMoveChecklistItem,
	deleteChecklistItem as apiDeleteChecklistItem,
} from '../services/api.js'
import { boardQueryKey } from './queryKeys.js'

/**
 * Resolve a value that may be a plain primitive, a Vue ref, or a getter fn.
 * @param {any} v
 * @returns {any}
 */
function resolve(v) {
	if (typeof v === 'function') return v()
	if (v !== null && typeof v === 'object' && 'value' in v) return v.value
	return v
}

/**
 * Recompute checklist summary counts from a list of items.
 * @param {Array} items
 * @returns {{ total: number, done: number }}
 */
function summarise(items) {
	if (!Array.isArray(items)) return { total: 0, done: 0 }
	return {
		total: items.length,
		done: items.filter((i) => i.done).length,
	}
}

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} cardId
 * @param {import('vue').Ref<string|number>|string|number|Function} boardId
 */
export function useChecklist(cardId, boardId) {
	const queryClient = useQueryClient()

	function getCardId() {
		return String(resolve(cardId))
	}

	function getChecklistKey() {
		return ['checklist', getCardId()]
	}

	function getCardKey() {
		return ['card', getCardId()]
	}

	function getBoardKey() {
		return boardQueryKey(resolve(boardId))
	}

	// ── Items query ─────────────────────────────────────────────────────────────
	// Seeded from card detail (checklistItems field) when available, otherwise
	// fetches /api/cards/{id}/checklist.
	const items = useQuery({
		queryKey: computed(() => getChecklistKey()),
		queryFn: () => apiFetchChecklist(getCardId()),
		// Gated to a numeric card id: a card can be addressed by its human id in
		// the URL (e.g. /card/KAN-123, #3611), which the modal resolves + redirects
		// to the numeric id. Firing this against the raw ref would be a
		// guaranteed-failing GET /api/cards/KAN-123/checklist before the redirect.
		enabled: computed(() => /^\d+$/.test(getCardId())),
		// Seed initial data from the card detail cache if it has checklistItems
		initialData: () => {
			const card = queryClient.getQueryData(getCardKey())
			return Array.isArray(card?.checklistItems) ? card.checklistItems : undefined
		},
		initialDataUpdatedAt: () => {
			return queryClient.getQueryState(getCardKey())?.dataUpdatedAt ?? 0
		},
		// No client-side re-sort: the server returns items ordered by sortKey
		// (CardController::show and the checklist index both order by sort_key),
		// and every optimistic write below preserves position. Re-sorting here by
		// sortKey would fight the optimistic reorder (the moved item still carries
		// its old key for the settle window and would visibly snap back).
	})

	// ── Shared helper: patch card detail checklist summary in place ─────────────
	function patchCardDetailSummary(items) {
		queryClient.setQueryData(getCardKey(), (old) => {
			if (!old) return old
			return { ...old, checklist: summarise(items), checklistItems: items }
		})
	}

	// ── Shared helper: patch board card summary in place ────────────────────────
	function patchBoardCardSummary(summary) {
		const boardKey = getBoardKey()
		// Only patch if the board is actually in the cache (it may not be for
		// standalone card views).
		queryClient.setQueryData(boardKey, (old) => {
			if (!old) return old
			const numericCardId = Number(getCardId())
			return {
				...old,
				cards: old.cards.map((c) =>
					c.id === numericCardId ? { ...c, checklist: summary } : c,
				),
			}
		})
	}

	// ── addItem ─────────────────────────────────────────────────────────────────
	const addItem = useMutation({
		mutationFn: ({ title }) => apiCreateChecklistItem(getCardId(), { title }),

		onMutate: async ({ title }) => {
			const checklistKey = getChecklistKey()
			const cardKey = getCardKey()
			const boardKey = getBoardKey()

			await queryClient.cancelQueries({ queryKey: checklistKey })
			await queryClient.cancelQueries({ queryKey: cardKey })
			await queryClient.cancelQueries({ queryKey: boardKey })

			const previousChecklist = queryClient.getQueryData(checklistKey)
			const previousCard = queryClient.getQueryData(cardKey)
			const previousBoard = queryClient.getQueryData(boardKey)

			// Optimistic item - use a temporary negative id to avoid collision
			const tempId = -(Date.now())
			// Append after the last item (highest sortKey) - use a placeholder
			const currentItems = Array.isArray(previousChecklist) ? previousChecklist : []
			const lastSortKey = currentItems.length > 0
				? currentItems.reduce((max, i) => (i.sortKey > max ? i.sortKey : max), currentItems[0].sortKey)
				: 'I'
			// Temporary sortKey: append '~' which sorts after all base-36 chars
			const tempSortKey = lastSortKey + '~'
			const optimisticItem = {
				id: tempId,
				cardId: Number(getCardId()),
				title,
				done: false,
				sortKey: tempSortKey,
			}

			const nextItems = [...currentItems, optimisticItem]
			queryClient.setQueryData(checklistKey, nextItems)
			patchCardDetailSummary(nextItems)
			patchBoardCardSummary(summarise(nextItems))

			return { previousChecklist, previousCard, previousBoard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousChecklist !== undefined) {
				queryClient.setQueryData(getChecklistKey(), context.previousChecklist)
			}
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getChecklistKey() })
			queryClient.invalidateQueries({ queryKey: getCardKey() })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── toggleItem ──────────────────────────────────────────────────────────────
	const toggleItem = useMutation({
		mutationFn: ({ item }) => apiUpdateChecklistItem(item.id, { done: !item.done }),

		onMutate: async ({ item }) => {
			const checklistKey = getChecklistKey()
			const cardKey = getCardKey()
			const boardKey = getBoardKey()

			await queryClient.cancelQueries({ queryKey: checklistKey })
			await queryClient.cancelQueries({ queryKey: cardKey })
			await queryClient.cancelQueries({ queryKey: boardKey })

			const previousChecklist = queryClient.getQueryData(checklistKey)
			const previousCard = queryClient.getQueryData(cardKey)
			const previousBoard = queryClient.getQueryData(boardKey)

			const nextItems = (Array.isArray(previousChecklist) ? previousChecklist : []).map((i) =>
				i.id === item.id ? { ...i, done: !i.done } : i,
			)
			queryClient.setQueryData(checklistKey, nextItems)
			patchCardDetailSummary(nextItems)
			patchBoardCardSummary(summarise(nextItems))

			return { previousChecklist, previousCard, previousBoard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousChecklist !== undefined) {
				queryClient.setQueryData(getChecklistKey(), context.previousChecklist)
			}
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getChecklistKey() })
			queryClient.invalidateQueries({ queryKey: getCardKey() })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── renameItem ──────────────────────────────────────────────────────────────
	const renameItem = useMutation({
		mutationFn: ({ item, title }) => apiUpdateChecklistItem(item.id, { title }),

		onMutate: async ({ item, title }) => {
			const checklistKey = getChecklistKey()
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: checklistKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousChecklist = queryClient.getQueryData(checklistKey)
			const previousCard = queryClient.getQueryData(cardKey)

			const nextItems = (Array.isArray(previousChecklist) ? previousChecklist : []).map((i) =>
				i.id === item.id ? { ...i, title } : i,
			)
			queryClient.setQueryData(checklistKey, nextItems)
			patchCardDetailSummary(nextItems)

			return { previousChecklist, previousCard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousChecklist !== undefined) {
				queryClient.setQueryData(getChecklistKey(), context.previousChecklist)
			}
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getChecklistKey() })
			queryClient.invalidateQueries({ queryKey: getCardKey() })
		},
	})

	// ── deleteItem ──────────────────────────────────────────────────────────────
	const deleteItem = useMutation({
		mutationFn: ({ item }) => apiDeleteChecklistItem(item.id),

		onMutate: async ({ item }) => {
			const checklistKey = getChecklistKey()
			const cardKey = getCardKey()
			const boardKey = getBoardKey()

			await queryClient.cancelQueries({ queryKey: checklistKey })
			await queryClient.cancelQueries({ queryKey: cardKey })
			await queryClient.cancelQueries({ queryKey: boardKey })

			const previousChecklist = queryClient.getQueryData(checklistKey)
			const previousCard = queryClient.getQueryData(cardKey)
			const previousBoard = queryClient.getQueryData(boardKey)

			const nextItems = (Array.isArray(previousChecklist) ? previousChecklist : []).filter((i) => i.id !== item.id)
			queryClient.setQueryData(checklistKey, nextItems)
			patchCardDetailSummary(nextItems)
			patchBoardCardSummary(summarise(nextItems))

			return { previousChecklist, previousCard, previousBoard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousChecklist !== undefined) {
				queryClient.setQueryData(getChecklistKey(), context.previousChecklist)
			}
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getChecklistKey() })
			queryClient.invalidateQueries({ queryKey: getCardKey() })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	// ── moveItem ────────────────────────────────────────────────────────────────
	// afterItemId: id of the item to insert after, or null to move to the top.
	const moveItem = useMutation({
		mutationFn: ({ item, afterItemId }) => apiMoveChecklistItem(item.id, afterItemId ?? null),

		onMutate: async ({ item, afterItemId }) => {
			const checklistKey = getChecklistKey()
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: checklistKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousChecklist = queryClient.getQueryData(checklistKey)
			const previousCard = queryClient.getQueryData(cardKey)

			// Optimistic reorder: remove item from its current position and splice
			// it after the target item (or at the front if afterItemId is null).
			const current = Array.isArray(previousChecklist) ? [...previousChecklist] : []
			const withoutItem = current.filter((i) => i.id !== item.id)
			let insertIdx
			if (afterItemId === null) {
				insertIdx = 0
			} else {
				const afterIdx = withoutItem.findIndex((i) => i.id === afterItemId)
				insertIdx = afterIdx === -1 ? withoutItem.length : afterIdx + 1
			}
			const nextItems = [
				...withoutItem.slice(0, insertIdx),
				item,
				...withoutItem.slice(insertIdx),
			]
			queryClient.setQueryData(checklistKey, nextItems)
			patchCardDetailSummary(nextItems)

			return { previousChecklist, previousCard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousChecklist !== undefined) {
				queryClient.setQueryData(getChecklistKey(), context.previousChecklist)
			}
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getChecklistKey() })
			queryClient.invalidateQueries({ queryKey: getCardKey() })
		},
	})

	return {
		items,
		addItem,
		toggleItem,
		renameItem,
		deleteItem,
		moveItem,
	}
}

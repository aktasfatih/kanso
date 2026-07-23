// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useCardHierarchy — parent/child card mutations (set parent, clear parent, add child).
 *
 * Optimistic strategy mirrors useChecklist / useCardActions (dual-cache pattern):
 *   1. Cancel in-flight queries for all affected keys.
 *   2. Snapshot previous values for rollback on error.
 *   3. Optimistically patch:
 *      - The child card's detail cache (parentCardId field).
 *      - The parent card's detail cache (children array + childProgress).
 *      - The board cache (card.parentCardId and the parent card's childProgress summary).
 *   4. On settled: invalidate the child detail, the parent detail (if known),
 *      and the board so server truth wins.
 *
 * The composable accepts ids that may be plain values, Vue refs, or getter fns.
 */

import { useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	setCardParent as apiSetCardParent,
	createCard as apiCreateCard,
	deleteCard as apiDeleteCard,
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
 * Recompute childProgress from a children array in the card detail cache.
 * Each child summary has a `doneAt` field (unix timestamp or null/0).
 * @param {Array} children
 * @returns {{ total: number, done: number }}
 */
function childProgressFromChildren(children) {
	if (!Array.isArray(children)) return { total: 0, done: 0 }
	return {
		total: children.length,
		done: children.filter((c) => Number(c.doneAt) > 0).length,
	}
}

/**
 * @param {import('vue').Ref<string|number>|string|number|Function} boardId
 */
export function useCardHierarchy(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolve(boardId))
	}

	function getCardKey(id) {
		return ['card', String(id)]
	}

	// ── setParent ─────────────────────────────────────────────────────────────
	// Sets a new parent for a card. Pass parentCardId=null to clear.
	const setParentMutation = useMutation({
		mutationFn: ({ cardId, parentCardId }) =>
			apiSetCardParent(cardId, parentCardId),

		onMutate: async ({ cardId, parentCardId, previousParentCardId }) => {
			const boardKey = getBoardKey()
			const childKey = getCardKey(cardId)
			const newParentKey = parentCardId != null ? getCardKey(parentCardId) : null
			const oldParentKey = previousParentCardId != null ? getCardKey(previousParentCardId) : null

			// Cancel in-flight queries for all affected caches
			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: childKey })
			if (newParentKey) await queryClient.cancelQueries({ queryKey: newParentKey })
			if (oldParentKey) await queryClient.cancelQueries({ queryKey: oldParentKey })

			// Snapshot for rollback
			const previousBoard = queryClient.getQueryData(boardKey)
			const previousChild = queryClient.getQueryData(childKey)
			const previousNewParent = newParentKey ? queryClient.getQueryData(newParentKey) : undefined
			const previousOldParent = oldParentKey ? queryClient.getQueryData(oldParentKey) : undefined

			// --- Patch child card detail cache ---
			queryClient.setQueryData(childKey, (old) => {
				if (!old) return old
				return { ...old, parentCardId: parentCardId ?? null }
			})

			// --- Patch new parent's card detail cache (add child) ---
			if (newParentKey) {
				const childData = queryClient.getQueryData(childKey)
				queryClient.setQueryData(newParentKey, (old) => {
					if (!old) return old
					// Build a summary from whatever we know about the child
					const childSummary = {
						id: cardId,
						title: childData?.title ?? '',
						stackId: childData?.stackId ?? null,
						doneAt: childData?.doneAt ?? null,
						archived: childData?.archived ?? false,
					}
					const newChildren = [...(old.children ?? []), childSummary]
					return {
						...old,
						children: newChildren,
						childProgress: childProgressFromChildren(newChildren),
					}
				})
			}

			// --- Patch old parent's card detail cache (remove child) ---
			if (oldParentKey) {
				queryClient.setQueryData(oldParentKey, (old) => {
					if (!old) return old
					const newChildren = (old.children ?? []).filter((c) => c.id !== cardId)
					return {
						...old,
						children: newChildren,
						childProgress: childProgressFromChildren(newChildren),
					}
				})
			}

			// --- Patch board cache ---
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				const numericCardId = Number(cardId)
				const numericNewParentId = parentCardId != null ? Number(parentCardId) : null
				const numericOldParentId = previousParentCardId != null ? Number(previousParentCardId) : null

				return {
					...old,
					cards: old.cards.map((c) => {
						// Update the child card's parentCardId
						if (c.id === numericCardId) {
							return { ...c, parentCardId: numericNewParentId }
						}
						// Update new parent's childProgress (add one child)
						if (numericNewParentId != null && c.id === numericNewParentId) {
							const currentTotal = (c.childProgress?.total ?? 0) + 1
							const childDone = Number(old.cards.find((ch) => ch.id === numericCardId)?.doneAt ?? 0) > 0
							const currentDone = (c.childProgress?.done ?? 0) + (childDone ? 1 : 0)
							return { ...c, childProgress: { total: currentTotal, done: currentDone } }
						}
						// Update old parent's childProgress (remove one child)
						if (numericOldParentId != null && c.id === numericOldParentId) {
							const currentTotal = Math.max(0, (c.childProgress?.total ?? 1) - 1)
							const childWasDone = Number(old.cards.find((ch) => ch.id === numericCardId)?.doneAt ?? 0) > 0
							const currentDone = Math.max(0, (c.childProgress?.done ?? 0) - (childWasDone ? 1 : 0))
							return { ...c, childProgress: { total: currentTotal, done: currentDone } }
						}
						return c
					}),
				}
			})

			return {
				previousBoard,
				previousChild,
				previousNewParent,
				previousOldParent,
				boardKey,
				childKey,
				newParentKey,
				oldParentKey,
			}
		},

		onError: (_err, _vars, context) => {
			if (!context) return
			if (context.previousBoard !== undefined) {
				queryClient.setQueryData(context.boardKey, context.previousBoard)
			}
			if (context.previousChild !== undefined) {
				queryClient.setQueryData(context.childKey, context.previousChild)
			}
			if (context.previousNewParent !== undefined && context.newParentKey) {
				queryClient.setQueryData(context.newParentKey, context.previousNewParent)
			}
			if (context.previousOldParent !== undefined && context.oldParentKey) {
				queryClient.setQueryData(context.oldParentKey, context.previousOldParent)
			}
		},

		onSettled: (_data, _err, { cardId, parentCardId, previousParentCardId }) => {
			queryClient.invalidateQueries({ queryKey: getCardKey(cardId) })
			if (parentCardId != null) {
				queryClient.invalidateQueries({ queryKey: getCardKey(parentCardId) })
			}
			if (previousParentCardId != null) {
				queryClient.invalidateQueries({ queryKey: getCardKey(previousParentCardId) })
			}
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	/**
	 * Set the parent of a card.
	 * @param {number} cardId
	 * @param {number} parentCardId
	 * @param {number|null} [previousParentCardId] - the card's current parent (for optimistic old-parent update)
	 */
	function setParent(cardId, parentCardId, previousParentCardId = null) {
		return setParentMutation.mutateAsync({ cardId, parentCardId, previousParentCardId })
	}

	/**
	 * Detach a card from its parent (set parentCardId = null).
	 * @param {number} cardId
	 * @param {number|null} [currentParentCardId] - the card's current parent (for optimistic update)
	 */
	function clearParent(cardId, currentParentCardId = null) {
		return setParentMutation.mutateAsync({
			cardId,
			parentCardId: null,
			previousParentCardId: currentParentCardId,
		})
	}

	// ── addChild ──────────────────────────────────────────────────────────────
	// Creates a new card in the parent's stack, then sets its parent. No
	// optimistic patch for the create step (we don't have the new card's id
	// yet); the create+setParent pair is made atomic — if setParent fails the
	// just-created card is deleted so no orphan top-level card is left behind.
	const addChildMutation = useMutation({
		mutationFn: async ({ parentCard, title }) => {
			// Create the card in the same stack as the parent
			const newCard = await apiCreateCard({ stackId: parentCard.stackId, title })
			try {
				// Set the parent relationship
				const updated = await apiSetCardParent(newCard.id, parentCard.id)
				return { newCard, updated }
			} catch (err) {
				// Roll back the orphan so add-child is all-or-nothing.
				await apiDeleteCard(newCard.id).catch(() => {})
				throw err
			}
		},

		onSettled: (_data, _err, { parentCard }) => {
			queryClient.invalidateQueries({ queryKey: getCardKey(parentCard.id) })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	/**
	 * Add a new child card under the given parent card.
	 * @param {{ id: number, stackId: number }} parentCard
	 * @param {string} title
	 */
	function addChild(parentCard, title) {
		return addChildMutation.mutateAsync({ parentCard, title })
	}

	return {
		setParent,
		clearParent,
		addChild,
		setParentMutation,
		addChildMutation,
	}
}

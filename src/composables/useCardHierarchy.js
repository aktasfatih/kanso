// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	setCardParent as apiSetCardParent,
	createCard as apiCreateCard,
	deleteCard as apiDeleteCard,
} from '../services/api.js'
import { boardQueryKey } from './queryKeys.js'
import { beginHierarchyChange, executeHierarchyChange } from './cardHierarchy.js'

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

	const setParentMutation = useMutation({
		onMutate: ({ operation }) => operation.prepared,
		mutationFn: ({ operation }) => executeHierarchyChange(queryClient, operation),
	})

	function setParent(cardId, parentCardId, previousParentCardId = null) {
		const operation = beginHierarchyChange(queryClient, {
			boardId: resolve(boardId), cardId, parentCardId, previousParentCardId,
		})
		return setParentMutation.mutateAsync({ operation })
	}

	function clearParent(cardId, currentParentCardId = null) {
		return setParent(cardId, null, currentParentCardId)
	}

	// ── addChild ──────────────────────────────────────────────────────────────
	// Creates a new card in the parent's stack, then sets its parent. No
	// optimistic patch for the create step (we don't have the new card's id
	// yet); the create+setParent pair is made atomic - if setParent fails the
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

		onSettled: (_data, _err, { parentCard, boardKey }) => {
			queryClient.invalidateQueries({ queryKey: getCardKey(parentCard.id) })
			queryClient.invalidateQueries({ queryKey: boardKey })
		},
	})

	/**
	 * Add a new child card under the given parent card.
	 * @param {{ id: number, stackId: number }} parentCard
	 * @param {string} title
	 */
	function addChild(parentCard, title) {
		return addChildMutation.mutateAsync({
			parentCard: Object.freeze({ id: Number(parentCard.id), stackId: Number(parentCard.stackId) }),
			boardKey: getBoardKey(), title,
		})
	}

	return {
		setParent,
		clearParent,
		addChild,
		setParentMutation,
		addChildMutation,
	}
}

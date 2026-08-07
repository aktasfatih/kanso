// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQueryClient, useMutation } from '@tanstack/vue-query'
import {
	createCardField as apiCreateCardField,
	updateCardField as apiUpdateCardField,
	deleteCardField as apiDeleteCardField,
	setCardFieldValue as apiSetCardFieldValue,
	clearCardFieldValue as apiClearCardFieldValue,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'

function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

/**
 * Custom fields (#3537). DEFINITION mutations (create/update/delete) ride the
 * board payload, so they invalidate the board query. VALUE mutations
 * (set/clear) live in the card detail payload; the caller passes a cardId and
 * we invalidate that card's query so the modal refetches the fresh values.
 */
export function useCardFields(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolveBoardId(boardId))
	}

	const createCardField = useMutation({
		mutationFn: ({ name, type, options }) =>
			apiCreateCardField(resolveBoardId(boardId), name, type, options ?? null),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	const updateCardField = useMutation({
		mutationFn: ({ fieldId, name, options, sortKey }) =>
			apiUpdateCardField(fieldId, {
				...(name !== undefined ? { name } : {}),
				...(options !== undefined ? { options } : {}),
				...(sortKey !== undefined ? { sortKey } : {}),
			}),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	const deleteCardField = useMutation({
		mutationFn: ({ fieldId }) => apiDeleteCardField(fieldId),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	const setCardFieldValue = useMutation({
		mutationFn: ({ cardId, fieldId, value }) => apiSetCardFieldValue(cardId, fieldId, value),
		onSettled: (_data, _err, { cardId }) => {
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
		},
	})

	const clearCardFieldValue = useMutation({
		mutationFn: ({ cardId, fieldId }) => apiClearCardFieldValue(cardId, fieldId),
		onSettled: (_data, _err, { cardId }) => {
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
		},
	})

	return {
		createCardField,
		updateCardField,
		deleteCardField,
		setCardFieldValue,
		clearCardFieldValue,
	}
}

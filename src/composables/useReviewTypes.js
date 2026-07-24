// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQueryClient, useMutation } from '@tanstack/vue-query'
import {
	createReviewType as apiCreateReviewType,
	updateReviewType as apiUpdateReviewType,
	deleteReviewType as apiDeleteReviewType,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'

function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

export function useReviewTypes(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolveBoardId(boardId))
	}

	const createReviewType = useMutation({
		mutationFn: ({ title, color }) =>
			apiCreateReviewType(resolveBoardId(boardId), title, color || null),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	const updateReviewType = useMutation({
		mutationFn: ({ typeId, title, color }) =>
			apiUpdateReviewType(typeId, {
				...(title !== undefined ? { title } : {}),
				...(color !== undefined ? { color } : {}),
			}),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	const deleteReviewType = useMutation({
		mutationFn: ({ typeId }) => apiDeleteReviewType(typeId),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	return {
		createReviewType,
		updateReviewType,
		deleteReviewType,
	}
}

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { toValue } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { invalidateCrossBoardFeeds } from './queryKeys.js'
import { beginCardLifecycle, prepareCardLifecycle, executeCardLifecycle } from './cardLifecycle.js'

export function useCardActions(boardId, cardId) {
	const queryClient = useQueryClient()
	const captureTarget = () => ({ boardId: String(toValue(boardId)), cardId: Number(toValue(cardId)) })

	function lifecycleMutation(kind) {
		const mutation = useMutation({
			onMutate: (operation) => prepareCardLifecycle(queryClient, operation),
			mutationFn: (operation) => executeCardLifecycle(queryClient, operation),
			onSettled: (_data, _error, operation) => {
				queryClient.invalidateQueries({ queryKey: operation.target.boardKey })
				queryClient.invalidateQueries({ queryKey: operation.target.cardKey })
				queryClient.invalidateQueries({
					queryKey: ['card'],
					predicate: (query) => query.state.data?.children?.some((child) => Number(child.id) === operation.target.cardId),
				})
				invalidateCrossBoardFeeds(queryClient)
			},
		})

		function begin(variables = {}) {
			return beginCardLifecycle(queryClient, {
				...(variables.target ?? captureTarget()),
				kind,
				archived: variables.archived,
				undoOf: variables.undoOf,
			})
		}

		return {
			...mutation,
			mutate: (variables, options) => {
				const operation = begin(variables)
				if (operation) mutation.mutate(operation, options)
			},
			mutateAsync: (variables, options) => {
				const operation = begin(variables)
				return operation ? mutation.mutateAsync(operation, options) : Promise.resolve(null)
			},
		}
	}

	return {
		captureTarget,
		setArchived: lifecycleMutation('archive'),
		deleteCard: lifecycleMutation('delete'),
		restoreCard: lifecycleMutation('restore'),
	}
}

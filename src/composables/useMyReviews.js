// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { getMyReviews as apiGetMyReviews, setReviewState as apiSetReviewState } from '../services/api.js'
import { boardQueryKey, invalidateMyWork, MY_WORK_POLL_INTERVAL } from './queryKeys.js'

/**
 * Composable for the "My Reviews" feed - all review requests assigned to the
 * current user, across all boards they can read.
 */
export function useMyReviews() {
	const queryClient = useQueryClient()

	const query = useQuery({
		queryKey: ['my-reviews'],
		queryFn: apiGetMyReviews,
		// #3766: the nav badges keep this query permanently mounted, so a default
		// mount refetch is suppressed within staleTime - 'always' makes every
		// navigation to My Reviews revalidate against the server.
		refetchOnMount: 'always',
		// #3768: other users' review requests must appear while the user sits on
		// the page - polling is the only channel for this cross-board feed.
		// Paused on hidden tabs (TanStack default refetchIntervalInBackground).
		refetchInterval: MY_WORK_POLL_INTERVAL,
	})

	/**
	 * Set a verdict on one of the current user's pending review requests.
	 * Optimistically patches the my-reviews cache, then invalidates on settled.
	 * Also invalidates the relevant card + board caches so those views stay fresh.
	 */
	const setState = useMutation({
		mutationFn: ({ cardId, reviewId, state, reason }) => apiSetReviewState(cardId, reviewId, state, reason ?? null),

		onMutate: async ({ reviewId, state }) => {
			await queryClient.cancelQueries({ queryKey: ['my-reviews'] })
			const previous = queryClient.getQueryData(['my-reviews'])

			queryClient.setQueryData(['my-reviews'], (old) => {
				if (!Array.isArray(old)) return old
				return old.map((r) => r.id === reviewId ? { ...r, state } : r)
			})

			return { previous }
		},

		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(['my-reviews'], context.previous)
			}
		},

		onSettled: (_data, _err, { cardId, boardId }) => {
			// A verdict changes My Reviews membership (pending → answered), and the
			// other feeds render card context too - refresh the whole family (#3766).
			invalidateMyWork(queryClient)
			// Best-effort invalidation of related board/card caches
			if (cardId) {
				queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			}
			if (boardId) {
				queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId) })
			}
		},
	})

	return {
		...query,
		setState,
	}
}

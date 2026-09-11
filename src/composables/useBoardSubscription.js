// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useBoardSubscription - watch / unwatch a whole board.
 *
 * Optimistic strategy mirrors useSubscription, but patches the board query
 * cache's top-level `subscription` block ({subscribed, subscribers, count}):
 *   1. Cancel in-flight board queries.
 *   2. Snapshot for rollback.
 *   3. Flip `subscribed`, adjust `count`, add/remove the current uid.
 *   4. Rollback on error; invalidate on settle so server truth wins.
 *
 * `boardId` may be a plain value, a Vue ref, or a getter function.
 */

import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { getCurrentUser } from '@nextcloud/auth'
import {
	subscribeBoard as apiSubscribeBoard,
	unsubscribeBoard as apiUnsubscribeBoard,
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
 * @param {import('vue').Ref<string|number>|string|number|Function} boardId
 */
export function useBoardSubscription(boardId) {
	const queryClient = useQueryClient()

	function getBoardId() {
		return resolve(boardId)
	}

	function getBoardKey() {
		return boardQueryKey(getBoardId())
	}

	const toggle = useMutation({
		mutationFn: ({ subscribed }) =>
			subscribed
				? apiSubscribeBoard(getBoardId())
				: apiUnsubscribeBoard(getBoardId()),

		onMutate: async ({ subscribed }) => {
			const boardKey = getBoardKey()

			await queryClient.cancelQueries({ queryKey: boardKey })

			const previousBoard = queryClient.getQueryData(boardKey)

			const uid = getCurrentUser()?.uid ?? ''
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				const prev = old.subscription ?? { subscribed: false, subscribers: [], count: 0 }
				const prevSubscribers = Array.isArray(prev.subscribers) ? prev.subscribers : []

				let nextSubscribers
				let nextCount
				if (subscribed) {
					nextSubscribers = prevSubscribers.includes(uid)
						? prevSubscribers
						: [...prevSubscribers, uid]
					nextCount = Math.max(prev.count ?? 0, nextSubscribers.length)
				} else {
					nextSubscribers = prevSubscribers.filter((u) => u !== uid)
					nextCount = Math.max(0, (prev.count ?? 1) - 1)
				}

				return {
					...old,
					subscription: {
						...prev,
						subscribed,
						subscribers: nextSubscribers,
						count: nextCount,
					},
				}
			})

			return { previousBoard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
		},

		// Server truth, taken from the response that just produced it rather than
		// from a refetch. The endpoints return the board's whole watch block
		// ({subscribed, subscribers, count} - SubscriptionService::subscribeBoard),
		// so this is exact and costs nothing.
		//
		// It also has to be here rather than left to the settle-phase refetch:
		// watch state writes no kanso_changes row - deliberately, since it is one
		// user's private state while a change row is board-global and would make
		// every other viewer resync - so the board's ETag does not move when it
		// changes, and the conditional read below is answered 304. Relying on
		// that refetch would leave the optimistic count above standing in for the
		// server's.
		onSuccess: (serverState) => {
			if (typeof serverState?.subscribed !== 'boolean') {
				return
			}
			queryClient.setQueryData(getBoardKey(), (old) =>
				(old ? { ...old, subscription: serverState } : old),
			)
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
		},
	})

	return { toggle }
}

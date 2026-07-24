// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useSubscription — card watcher (subscribe / unsubscribe) mutation.
 *
 * Optimistic strategy mirrors useComments (dual-cache pattern):
 *   1. Cancel in-flight queries for the card detail key.
 *   2. Snapshot previous value for rollback on error.
 *   3. Optimistically patch the card detail cache's `subscription` block:
 *      - flip `subscribed`
 *      - adjust `count` by ±1
 *      - add or remove the current user's uid from `subscribers`
 *   4. On error: rollback to snapshot.
 *   5. On settled: invalidate the card detail query so server truth wins.
 *
 * The composable accepts a cardId that may be:
 *   - a plain number/string
 *   - a Vue ref
 *   - a getter function (e.g. () => props.cardId)
 */

import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { getCurrentUser } from '@nextcloud/auth'
import {
	subscribeCard as apiSubscribeCard,
	unsubscribeCard as apiUnsubscribeCard,
} from '../services/api.js'

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
 * @param {import('vue').Ref<string|number>|string|number|Function} cardId
 */
export function useSubscription(cardId) {
	const queryClient = useQueryClient()

	function getCardId() {
		return String(resolve(cardId))
	}

	function getCardKey() {
		return ['card', getCardId()]
	}

	/**
	 * Toggle subscription: pass `subscribed = true` to subscribe,
	 * `subscribed = false` to unsubscribe.
	 */
	const toggle = useMutation({
		mutationFn: ({ subscribed }) =>
			subscribed
				? apiSubscribeCard(getCardId())
				: apiUnsubscribeCard(getCardId()),

		onMutate: async ({ subscribed }) => {
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousCard = queryClient.getQueryData(cardKey)

			const uid = getCurrentUser()?.uid ?? ''
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				const prev = old.subscription ?? { subscribed: false, subscribers: [], count: 0 }
				const prevSubscribers = Array.isArray(prev.subscribers) ? prev.subscribers : []

				let nextSubscribers
				let nextCount
				if (subscribed) {
					// Add uid if not already present
					nextSubscribers = prevSubscribers.includes(uid)
						? prevSubscribers
						: [...prevSubscribers, uid]
					nextCount = Math.max(prev.count ?? 0, nextSubscribers.length)
				} else {
					// Remove uid
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

			return { previousCard }
		},

		onError: (_err, _vars, context) => {
			if (context?.previousCard !== undefined) {
				queryClient.setQueryData(getCardKey(), context.previousCard)
			}
		},

		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: getCardKey() })
		},
	})

	return { toggle }
}

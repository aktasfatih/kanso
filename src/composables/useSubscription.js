// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useSubscription - card watcher (subscribe / unsubscribe) mutation.
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
	subscribeWatcher as apiSubscribeWatcher,
	unsubscribeWatcher as apiUnsubscribeWatcher,
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

				const nextSubscribers = subscribed
					? (prevSubscribers.includes(uid) ? prevSubscribers : [...prevSubscribers, uid])
					: prevSubscribers.filter((u) => u !== uid)

				return {
					...old,
					subscription: {
						...prev,
						subscribed,
						subscribers: nextSubscribers,
						// The server's watch block always returns the FULL subscriber
						// list, so count === subscribers.length — same rule as toggleOther.
						count: nextSubscribers.length,
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

	/**
	 * Add or remove ANOTHER board participant as a watcher. Patches only the
	 * subscribers list + count (the actor's own `subscribed` flag is unchanged);
	 * server enforces EDIT on the actor and READ on the target.
	 */
	const toggleOther = useMutation({
		mutationFn: ({ userId, subscribed }) =>
			subscribed
				? apiSubscribeWatcher(getCardId(), userId)
				: apiUnsubscribeWatcher(getCardId(), userId),

		onMutate: async ({ userId, subscribed }) => {
			const cardKey = getCardKey()

			await queryClient.cancelQueries({ queryKey: cardKey })

			const previousCard = queryClient.getQueryData(cardKey)

			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				const prev = old.subscription ?? { subscribed: false, subscribers: [], count: 0 }
				const prevSubscribers = Array.isArray(prev.subscribers) ? prev.subscribers : []

				const nextSubscribers = subscribed
					? (prevSubscribers.includes(userId) ? prevSubscribers : [...prevSubscribers, userId])
					: prevSubscribers.filter((u) => u !== userId)

				return {
					...old,
					subscription: {
						...prev,
						subscribers: nextSubscribers,
						count: nextSubscribers.length,
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

	return { toggle, toggleOther }
}

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	getProjects as apiGetProjects,
	createProject as apiCreateProject,
	updateProject as apiUpdateProject,
	deleteProject as apiDeleteProject,
	addCardToProject as apiAddCardToProject,
	removeCardFromProject as apiRemoveCardFromProject,
} from '../services/api.js'
import { createToggleQueue } from './useToggleQueue.js'

/**
 * Composable for the projects list — create / update / delete mutations
 * all invalidate ['projects'] so every consumer stays in sync.
 */
export function useProjects() {
	const queryClient = useQueryClient()

	const query = useQuery({
		queryKey: ['projects'],
		queryFn: apiGetProjects,
	})

	const create = useMutation({
		mutationFn: (data) => apiCreateProject(data),
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	const update = useMutation({
		mutationFn: ({ id, ...data }) => apiUpdateProject(id, data),
		onMutate: async ({ id, ...data }) => {
			await queryClient.cancelQueries({ queryKey: ['projects'] })
			const previous = queryClient.getQueryData(['projects'])
			queryClient.setQueryData(['projects'], (old) => {
				if (!Array.isArray(old)) return old
				return old.map((p) => p.id === id ? { ...p, ...data } : p)
			})
			return { previous }
		},
		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(['projects'], context.previous)
			}
		},
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	const remove = useMutation({
		mutationFn: (id) => apiDeleteProject(id),
		onMutate: async (id) => {
			await queryClient.cancelQueries({ queryKey: ['projects'] })
			const previous = queryClient.getQueryData(['projects'])
			queryClient.setQueryData(['projects'], (old) => {
				if (!Array.isArray(old)) return old
				return old.filter((p) => p.id !== id)
			})
			return { previous }
		},
		onError: (_err, _vars, context) => {
			if (context?.previous !== undefined) {
				queryClient.setQueryData(['projects'], context.previous)
			}
		},
		onSettled: () => {
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	return {
		...query,
		create,
		update,
		remove,
	}
}

/**
 * One card's project membership, as the card modal's Projects picker toggles it.
 *
 * The third caller of the shared toggle queue (#10922), after the assignee
 * (#10799) and label (#10920) pickers. Its defect was NOT theirs, though, and
 * the difference is worth recording because the obvious fix for the other two
 * would have missed it: this picker never had a single-flight `return` in its
 * handler at all. It bound the whole-picker pending boolean to `:disabled` on
 * EVERY row, and a browser simply does not deliver a click to a disabled button
 * — so the gesture was swallowed one layer below the JavaScript.
 *
 * Measured in the browser, three project rows clicked with nothing awaited
 * between them (trusted mouse clicks, the picker's own rows):
 *   - as shipped: 1 of 3 picks reached the server with the write delayed 800ms,
 *     and 1 of 3 with NO added latency at all — the worst of the three pickers.
 *   - the same three clicks dispatched past the `disabled` attribute: 3 of 3 at
 *     both latencies, which is what pins `disabled` as the whole cause.
 *
 * So dropping `disabled` for `aria-busy` (#10705) is what stops the drop here,
 * and it is also what re-opens the double-submit `disabled` was covering: an
 * `aria-busy` row stays in the focus order, so a held-down Enter repeats it.
 * That guard is the queue's, per row, which is why this picker is wired to the
 * same `createToggleQueue` rather than to a third hand-written mechanism (see
 * the warning at src/main.js:192-197, and useToggleQueue.js for the rest).
 *
 * Every write takes its card from the MUTATION VARIABLES, never from a card id
 * bound at composable level — which is why this takes no cardId argument at all.
 * Queuing the picks is what made that distinction matter: the card modal is
 * rendered through an UNKEYED router-view, so navigating card→card REUSES the
 * component and `props.cardId` changes under it (see the watcher in
 * CardDetail.vue). A pick waiting its turn in the queue outlives that switch, so
 * a composable-level id would be read at EXECUTION time and send the pick for
 * whichever card happens to be open then — and invalidate that card's key too.
 * The queue's own `keyOf` already keyed off `vars.cardId`, so the two disagreed.
 *
 * @return {{enqueueToggle: (vars: {cardId: number|string, projectId: number, assign: boolean}) => Promise<*>,
 *   isTogglePending: (cardId: number|string, projectId: number) => boolean}}
 */
export function useCardProjects() {
	const queryClient = useQueryClient()

	const toggleMembership = useMutation({
		mutationFn: ({ cardId, projectId, assign }) => assign
			? apiAddCardToProject(projectId, Number(cardId))
			: apiRemoveCardFromProject(projectId, Number(cardId)),
		// No optimistic patch, deliberately: the card detail carries `projectIds`
		// and the project page carries its own card list, and a project is the
		// viewer's OWN collection that another member's board rights cannot be
		// inferred from (see the picker's comment in CardDetail.vue). The settle
		// invalidation below is the only writer, so there is no snapshot to roll
		// back and no window in which a rollback could resurrect a stale id.
		onSettled: (_data, _err, { cardId, projectId }) => {
			// Refreshes the card's `projectIds` (the picker's ticks and the pill's
			// count both read it) — the card this pick was MADE for, which is not
			// necessarily the one open now.
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			// ...the project's own card feed...
			queryClient.invalidateQueries({ queryKey: ['project', String(projectId), 'cards'] })
			// ...and the projects list, whose rows show a card count.
			queryClient.invalidateQueries({ queryKey: ['projects'] })
		},
	})

	const { enqueue, isPending } = createToggleQueue({
		mutate: (vars) => toggleMembership.mutateAsync(vars),
		keyOf: ({ cardId: card, projectId }) => `${card}:${projectId}`,
	})

	/**
	 * Queue one add/remove behind whatever is already running.
	 *
	 * @param {{cardId: number|string, projectId: number, assign: boolean}} vars
	 * @return {Promise<*>} this pick's own outcome - resolves with the mutation
	 *   result, with TOGGLE_ALREADY_PENDING when it was a same-row
	 *   double-submit, or rejects with this pick's error.
	 */
	function enqueueToggle({ cardId: card, projectId, assign }) {
		return enqueue({ cardId: card, projectId, assign })
	}

	/**
	 * Whether this (card, project) toggle is on the wire or waiting its turn.
	 * Reactive - a template reading it re-renders when a toggle starts or ends,
	 * which is what repaints `aria-busy` on the one row being written.
	 *
	 * @param {number|string} card
	 * @param {number} projectId
	 * @return {boolean}
	 */
	function isTogglePending(card, projectId) {
		return isPending({ cardId: card, projectId })
	}

	// `toggleMembership` itself is deliberately NOT returned, for the reason
	// spelled out at the end of useLabels.js: an un-queued handle to the same
	// mutation is how a pick would get past the queue, and a guard a caller can
	// opt out of in one destructure is not a guard.
	return {
		enqueueToggle,
		isTogglePending,
	}
}

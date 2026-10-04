// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from 'vue'
import { useQueryClient, useQuery, useMutation } from '@tanstack/vue-query'
import {
	fetchParticipants as apiFetchParticipants,
	assignUser as apiAssignUser,
	unassignUser as apiUnassignUser,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'
import { invalidateCrossBoardFeeds, participantsQueryKey } from './queryKeys.js'
import { createToggleQueue } from './useToggleQueue.js'

/**
 * Resolve a boardId argument that may be a plain value, a Vue ref (.value),
 * a computed ref (.value), or a plain getter function (e.g. () => props.boardId).
 * When the ref hasn't resolved yet its `.value` is undefined — return that
 * undefined (NOT the ref object), so callers see a clean "not known yet" and can
 * guard the query. Returning the ref itself would stringify to "[object Object]"
 * in the URL (a bogus /boards/[object Object]/participants request). This happens
 * on the full-page card route, where boardId is only known once the card loads.
 */
function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && 'value' in boardId) return boardId.value
	return boardId
}

// A board id is usable in a request only once it's a real primitive (number or a
// numeric string) — undefined/null (ref not resolved yet) must not be fetched.
function isUsableBoardId(id) {
	return id !== null && id !== undefined && id !== 'undefined'
}

/**
 * Assignee queries and mutations for a given board.
 *
 * Optimistic strategy for toggleAssignee (assign / unassign):
 *   Mirrors useLabels' onMutate EXACTLY - patch assigneeIds in BOTH the board
 *   summary cache (via boardQueryKey) and the ['card', String(cardId)] detail
 *   cache; on error put back assigneeIds AND ONLY assigneeIds in both (#10923);
 *   invalidate both on settled.
 *
 * Toggles are SERIALISED through enqueueToggle, not fired in parallel and not
 * dropped - see useToggleQueue.js (shared with the label picker) for why that
 * distinction is the whole point (#10799).
 */
export function useAssignees(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolveBoardId(boardId))
	}

	// ── Participants query ──────────────────────────────────────────────────────
	// staleTime: 3 minutes - participants list changes rarely. That is a cache
	// policy, not a freshness mechanism: the one action that DOES change the list
	// is a share add/change/revoke, and useAcl invalidates participantsQueryKey on
	// settle, so the picker repaints on the share itself rather than waiting out
	// the window. Do not shorten this staleTime to chase freshness - that would
	// only blur the symptom and cost a refetch every three minutes.
	// Key/fetch/enabled are all reactive to boardId: on the full-page card route the
	// board id is undefined at setup and only resolves once the card loads, so a
	// non-reactive read would freeze this query on the unresolved value and never
	// refetch (a broken assignee picker). Guarded so it doesn't fire until known.
	const participants = useQuery({
		queryKey: computed(() => participantsQueryKey(resolveBoardId(boardId))),
		queryFn: () => apiFetchParticipants(resolveBoardId(boardId)),
		enabled: computed(() => isUsableBoardId(resolveBoardId(boardId))),
		staleTime: 3 * 60 * 1000,
	})

	// The cached page is {items, truncated, limit} (#10704). `participantList` is the
	// array every consumer wants; `participantsTruncated` says whether the server had
	// MORE to give than this page - the one fact that separates "this is everyone
	// on the board" from "these are the first `participantsLimit`", and so the one
	// thing that decides whether the picker may present the list as complete.
	const participantList = computed(() => {
		const page = participants.data.value
		return Array.isArray(page?.items) ? page.items : []
	})
	const participantsTruncated = computed(() => participants.data.value?.truncated === true)
	const participantsLimit = computed(() => participants.data.value?.limit ?? 0)

	// ── Toggle assignee on a card (assign / unassign) ───────────────────────────
	// assign = true → assign, assign = false → unassign
	const toggleAssignee = useMutation({
		mutationFn: ({ cardId, userId, assign }) =>
			assign ? apiAssignUser(cardId, userId) : apiUnassignUser(cardId, userId),

		onMutate: async ({ cardId, userId, assign }) => {
			// Cancel in-flight queries so they don't overwrite the patches
			const boardKey = getBoardKey()
			const cardKey = ['card', String(cardId)]
			await queryClient.cancelQueries({ queryKey: boardKey })
			await queryClient.cancelQueries({ queryKey: cardKey })

			// Snapshot ONLY `assigneeIds`, not the whole cached objects (#10923) -
			// the mirror of useLabels' snapshot, and for the same reason: this
			// picker and the label picker have a queue each, so their writes
			// overlap, and a whole-object restore on failure erases the label the
			// user picked meanwhile even though the server kept it.
			const snapshotIds = (card) => card
				? (Array.isArray(card.assigneeIds) ? card.assigneeIds : [])
				: undefined
			const previousDetailIds = snapshotIds(queryClient.getQueryData(cardKey))
			const previousSummaryIds = snapshotIds(
				queryClient.getQueryData(boardKey)?.cards?.find((c) => c.id === cardId),
			)

			const patchIds = (ids) => assign
				? (ids.includes(userId) ? ids : [...ids, userId])
				: ids.filter((id) => id !== userId)

			// Optimistically patch the card's assigneeIds in the board summary cache
			queryClient.setQueryData(boardKey, (old) => {
				if (!old) return old
				return {
					...old,
					cards: old.cards.map((c) => {
						if (c.id !== cardId) return c
						return { ...c, assigneeIds: patchIds(Array.isArray(c.assigneeIds) ? c.assigneeIds : []) }
					}),
				}
			})

			// ...and in the detail cache
			queryClient.setQueryData(cardKey, (old) => {
				if (!old) return old
				return { ...old, assigneeIds: patchIds(Array.isArray(old.assigneeIds) ? old.assigneeIds : []) }
			})

			return { previousDetailIds, previousSummaryIds, cardKey }
		},

		onError: (_err, { cardId }, context) => {
			// Put `assigneeIds` back, and ONLY `assigneeIds` - layered onto
			// whatever the cache holds at rollback time. See onMutate.
			if (context?.previousSummaryIds !== undefined) {
				queryClient.setQueryData(getBoardKey(), (old) => {
					if (!old) return old
					return {
						...old,
						cards: old.cards.map((c) => (c.id === cardId
							? { ...c, assigneeIds: context.previousSummaryIds }
							: c)),
					}
				})
			}
			if (context?.previousDetailIds !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, (old) => {
					if (!old) return old
					return { ...old, assigneeIds: context.previousDetailIds }
				})
			}
		},

		onSettled: (_data, _err, { cardId }) => {
			// Sync card detail query and board query with server truth
			queryClient.invalidateQueries({ queryKey: ['card', String(cardId)] })
			queryClient.invalidateQueries({ queryKey: getBoardKey() })
			// Assign/unassign changes My Tasks membership (#3766, #9859).
			invalidateCrossBoardFeeds(queryClient)
		},
	})

	// ── Serialising the picker's picks (#10799) ─────────────────────────────────
	// One pick at a time, queued rather than dropped, keyed per (card, person).
	// The mechanism - and the measurements behind it - live in useToggleQueue.js,
	// shared with the label picker, which had the identical defect (#10920).
	const { enqueue, isPending } = createToggleQueue({
		mutate: (vars) => toggleAssignee.mutateAsync(vars),
		keyOf: ({ cardId, userId }) => `${cardId}:${userId}`,
	})

	/**
	 * Queue one assign/unassign behind whatever is already running.
	 *
	 * @param {{cardId: number, userId: string, assign: boolean}} vars
	 * @return {Promise<*>} this pick's own outcome - resolves with the mutation
	 *   result, with TOGGLE_ALREADY_PENDING when it was a same-row
	 *   double-submit, or rejects with this pick's error.
	 */
	function enqueueToggle({ cardId, userId, assign }) {
		return enqueue({ cardId, userId, assign })
	}

	/**
	 * Whether this (card, person) toggle is on the wire or waiting its turn.
	 * Reactive - a template reading it re-renders when a toggle starts or ends.
	 *
	 * @param {number|string} cardId
	 * @param {string} userId
	 * @return {boolean}
	 */
	function isTogglePending(cardId, userId) {
		return isPending({ cardId, userId })
	}

	// `toggleAssignee` itself is deliberately NOT returned, for the reason spelled
	// out at the end of useLabels.js: an un-queued handle to the same mutation is
	// how a pick would get past the queue. enqueueToggle is the only way in.
	return {
		participants,
		participantList,
		participantsTruncated,
		participantsLimit,
		enqueueToggle,
		isTogglePending,
	}
}

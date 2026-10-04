// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, ref } from 'vue'
import { useQueryClient, useQuery, useMutation } from '@tanstack/vue-query'
import {
	fetchParticipants as apiFetchParticipants,
	assignUser as apiAssignUser,
	unassignUser as apiUnassignUser,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'
import { invalidateCrossBoardFeeds, participantsQueryKey } from './queryKeys.js'

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
 * Returned by enqueueToggle when the very same (card, person) write is already
 * in flight or queued - the double-submit a held-down Enter produces (#10705).
 * Nothing was sent, so the caller must not announce an assignment for it.
 *
 * @type {symbol}
 */
export const TOGGLE_ALREADY_PENDING = Symbol('kanso:assignee-toggle-already-pending')

/**
 * Assignee queries and mutations for a given board.
 *
 * Optimistic strategy for toggleAssignee (assign / unassign):
 *   Mirrors useLabels' onMutate EXACTLY - patch assigneeIds in BOTH the board
 *   summary cache (via boardQueryKey) and the ['card', String(cardId)] detail
 *   cache; rollback both on error; invalidate both on settled.
 *
 * Toggles are SERIALISED through enqueueToggle, not fired in parallel and not
 * dropped - see the comment on the queue below for why that distinction is the
 * whole point (#10799).
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

			// Snapshot previous state for potential rollback
			const previousBoard = queryClient.getQueryData(boardKey)
			const previousCard = queryClient.getQueryData(cardKey)

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

			return { previousBoard, previousCard, cardKey }
		},

		onError: (_err, _vars, context) => {
			// Roll back to the snapshots taken before the optimistic patches
			if (context?.previousBoard !== undefined) {
				queryClient.setQueryData(getBoardKey(), context.previousBoard)
			}
			if (context?.previousCard !== undefined && context?.cardKey) {
				queryClient.setQueryData(context.cardKey, context.previousCard)
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
	// The picker stays open across picks (#10603), so adding a second and third
	// person is three clicks in a row - and three clicks are quicker than one
	// round trip on anything but a fast link. Those clicks used to be DISCARDED:
	// the modal held a single `assigneeTogglePending` uid and returned early
	// while it was set, so a pick taken during the previous write's round trip
	// sent no request at all. The row stayed unticked, no error was shown, and
	// the only evidence was the person the user had just picked not being on the
	// card - the data loss behind the flakiness of card-multi-assign.spec.js's
	// "a second and third assignee go on without reopening the picker"
	// (measured: with the assign write delayed 1200ms, 2 of 3 picks reached the
	// server; with no added latency, 3 of 3). The test right after it holds the
	// write up on purpose, so the same loss is deterministic rather than a
	// once-in-a-while red run.
	//
	// So a pick for a DIFFERENT row is queued behind the one on the wire instead
	// of dropped, exactly like useCardMove's FIFO move queue (`queue =
	// queue.then(...)`, useCardMove.js:154) - the established in-repo answer to
	// "the user gestured again before the server answered". Serial, not parallel:
	// the optimistic patch/rollback in onMutate/onError snapshots the cache, and
	// overlapping snapshots is how a rollback resurrects a value a newer pick had
	// already replaced.
	//
	// A second click on the SAME row while that row is writing is still dropped.
	// That is the double-submit the pending flag was introduced for (#10705) -
	// `aria-busy` leaves the row in the focus order, so a held-down Enter repeats
	// it - and it is per-row here, which is the part that was wrong before.
	const togglesPending = ref(new Set())
	let toggleQueue = Promise.resolve()

	function toggleKey(cardId, userId) {
		return `${cardId}:${userId}`
	}

	/**
	 * Whether this (card, person) toggle is on the wire or waiting its turn.
	 * Reactive: the Set lives in a ref, so a template reading this re-renders
	 * when a toggle starts or finishes.
	 *
	 * @param {number|string} cardId
	 * @param {string} userId
	 * @return {boolean}
	 */
	function isTogglePending(cardId, userId) {
		return togglesPending.value.has(toggleKey(cardId, userId))
	}

	/**
	 * Queue one assign/unassign behind whatever is already running.
	 *
	 * @param {{cardId: number, userId: string, assign: boolean}} vars
	 * @return {Promise<*>} this pick's own outcome - resolves with the mutation
	 *   result, with TOGGLE_ALREADY_PENDING when it was a same-row
	 *   double-submit, or rejects with this pick's error.
	 */
	function enqueueToggle({ cardId, userId, assign }) {
		const key = toggleKey(cardId, userId)
		if (togglesPending.value.has(key)) {
			return Promise.resolve(TOGGLE_ALREADY_PENDING)
		}
		togglesPending.value.add(key)
		const run = async () => {
			try {
				return await toggleAssignee.mutateAsync({ cardId, userId, assign })
			} finally {
				togglesPending.value.delete(key)
			}
		}
		// Chained onto BOTH outcomes of the previous pick: one refused pick must
		// not strand every pick queued behind it. The caller gets this pick's own
		// promise (so its error is still its own), while the chain carries a
		// swallowed copy so a rejection never becomes an unhandled one.
		const settled = toggleQueue.then(run, run)
		toggleQueue = settled.then(() => {}, () => {})
		return settled
	}

	return {
		participants,
		participantList,
		participantsTruncated,
		participantsLimit,
		toggleAssignee,
		enqueueToggle,
		isTogglePending,
	}
}

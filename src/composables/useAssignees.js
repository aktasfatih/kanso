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
import { invalidateCrossBoardFeeds } from './queryKeys.js'

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
 *   cache; rollback both on error; invalidate both on settled.
 */
export function useAssignees(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolveBoardId(boardId))
	}

	// ── Participants query ──────────────────────────────────────────────────────
	// staleTime: 3 minutes - participants list changes rarely.
	// Key/fetch/enabled are all reactive to boardId: on the full-page card route the
	// board id is undefined at setup and only resolves once the card loads, so a
	// non-reactive read would freeze this query on the unresolved value and never
	// refetch (a broken assignee picker). Guarded so it doesn't fire until known.
	const participants = useQuery({
		queryKey: computed(() => ['participants', resolveBoardId(boardId)]),
		queryFn: () => apiFetchParticipants(resolveBoardId(boardId)),
		enabled: computed(() => isUsableBoardId(resolveBoardId(boardId))),
		staleTime: 3 * 60 * 1000,
	})

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

	return {
		participants,
		toggleAssignee,
	}
}

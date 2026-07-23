// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQueryClient, useQuery, useMutation } from '@tanstack/vue-query'
import {
	fetchParticipants as apiFetchParticipants,
	assignUser as apiAssignUser,
	unassignUser as apiUnassignUser,
} from '../services/api.js'
import { boardQueryKey } from './useBoard.js'

/**
 * Resolve a boardId argument that may be a plain value, a Vue ref (.value),
 * a computed ref (.value), or a plain getter function (e.g. () => props.boardId).
 */
function resolveBoardId(boardId) {
	if (typeof boardId === 'function') return boardId()
	if (boardId !== null && typeof boardId === 'object' && boardId.value !== undefined) return boardId.value
	return boardId
}

/**
 * Assignee queries and mutations for a given board.
 *
 * Optimistic strategy for toggleAssignee (assign / unassign):
 *   Mirrors useLabels' onMutate EXACTLY — patch assigneeIds in BOTH the board
 *   summary cache (via boardQueryKey) and the ['card', String(cardId)] detail
 *   cache; rollback both on error; invalidate both on settled.
 */
export function useAssignees(boardId) {
	const queryClient = useQueryClient()

	function getBoardKey() {
		return boardQueryKey(resolveBoardId(boardId))
	}

	// ── Participants query ──────────────────────────────────────────────────────
	// staleTime: 3 minutes — participants list changes rarely
	const participants = useQuery({
		queryKey: ['participants', resolveBoardId(boardId)],
		queryFn: () => apiFetchParticipants(resolveBoardId(boardId)),
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
		},
	})

	return {
		participants,
		toggleAssignee,
	}
}

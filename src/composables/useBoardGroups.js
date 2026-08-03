// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useBoardGroups — per-user board folders for the nav (#3529).
 *
 * FLAT, one-level, per-user folders. Two pieces of server state cooperate:
 *   - the folder list (['board-groups']) — names + order, from fetchBoardGroups.
 *   - each board's groupId, which rides the shared boards query (['boards']).
 *
 * So every mutation that changes membership (assign/unassign) or removes a
 * folder invalidates BOTH keys: the folder definitions AND the boards payload
 * that carries the per-board groupId. Database-first: no optimistic patch here
 * (folders are cheap, low-frequency nav edits); the server truth reloads.
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchBoardGroups,
	createBoardGroup as apiCreate,
	renameBoardGroup as apiRename,
	deleteBoardGroup as apiDelete,
	reorderBoardGroups as apiReorder,
	assignBoardToGroup as apiAssign,
	unassignBoardFromGroup as apiUnassign,
} from '../services/api.js'

export function useBoardGroups() {
	const queryClient = useQueryClient()

	const query = useQuery({
		queryKey: ['board-groups'],
		queryFn: fetchBoardGroups,
	})

	const invalidateGroups = () =>
		queryClient.invalidateQueries({ queryKey: ['board-groups'] })
	// Membership also lives on the board-list payload (groupId), so refresh it too.
	const invalidateGroupsAndBoards = () => {
		queryClient.invalidateQueries({ queryKey: ['board-groups'] })
		queryClient.invalidateQueries({ queryKey: ['boards'] })
	}

	const createGroup = useMutation({
		mutationFn: (name) => apiCreate(name),
		onSettled: invalidateGroups,
	})

	const renameGroup = useMutation({
		mutationFn: ({ id, name }) => apiRename(id, name),
		onSettled: invalidateGroups,
	})

	const deleteGroup = useMutation({
		mutationFn: (id) => apiDelete(id),
		// Deleting a folder ungroups its boards → the boards payload changes too.
		onSettled: invalidateGroupsAndBoards,
	})

	const reorderGroups = useMutation({
		mutationFn: (groupIds) => apiReorder(groupIds),
		onSettled: invalidateGroups,
	})

	const assignBoard = useMutation({
		mutationFn: ({ groupId, boardId }) => apiAssign(groupId, boardId),
		onSettled: invalidateGroupsAndBoards,
	})

	const unassignBoard = useMutation({
		mutationFn: (boardId) => apiUnassign(boardId),
		onSettled: invalidateGroupsAndBoards,
	})

	return {
		...query,
		createGroup,
		renameGroup,
		deleteGroup,
		reorderGroups,
		assignBoard,
		unassignBoard,
	}
}

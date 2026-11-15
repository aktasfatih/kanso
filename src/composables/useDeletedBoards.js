// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { fetchDeletedBoards, restoreBoard } from '../services/api.js'
import { boardQueryKey, invalidateCrossBoardFeeds } from './queryKeys.js'

export function useDeletedBoards() {
	const queryClient = useQueryClient()
	const query = useQuery({
		queryKey: ['deleted-boards'],
		queryFn: fetchDeletedBoards,
		gcTime: 0,
	})
	const restore = useMutation({
		mutationFn: restoreBoard,
		onSuccess: (_data, id) => {
			queryClient.invalidateQueries({ queryKey: boardQueryKey(id) })
			invalidateCrossBoardFeeds(queryClient)
		},
		onSettled: () => Promise.all([
			queryClient.invalidateQueries({ queryKey: ['deleted-boards'] }),
			queryClient.invalidateQueries({ queryKey: ['boards'] }),
		]),
	})
	return { ...query, restore }
}

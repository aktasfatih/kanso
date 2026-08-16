// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	getViews as apiGetViews,
	saveView as apiSaveView,
	renameView as apiRenameView,
	deleteView as apiDeleteView,
} from '../services/api.js'

/** TanStack key for the per-user saved-views list (cross-board, #3815). */
export const VIEWS_QUERY_KEY = ['views']

/**
 * The current user's saved cross-board Views (#3815) - the left-nav list and
 * the source a View page reads its name/filter/groupBy/display from. A small,
 * per-user, server-persisted list; kept mounted by the nav so it warms once.
 */
export function useViews() {
	const queryClient = useQueryClient()

	const query = useQuery({
		queryKey: VIEWS_QUERY_KEY,
		queryFn: apiGetViews,
		refetchOnWindowFocus: true,
		refetchOnMount: 'always',
	})

	// Every mutation returns the updated list, so seed the cache from it directly
	// (no extra refetch) and keep the nav in sync.
	const setList = (views) => queryClient.setQueryData(VIEWS_QUERY_KEY, views)

	const save = useMutation({
		mutationFn: apiSaveView,
		onSuccess: setList,
	})

	const rename = useMutation({
		mutationFn: ({ id, name }) => apiRenameView(id, name),
		onSuccess: setList,
	})

	const remove = useMutation({
		mutationFn: (id) => apiDeleteView(id),
		onSuccess: setList,
	})

	return { ...query, save, rename, remove }
}

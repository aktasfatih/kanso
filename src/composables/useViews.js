// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import {
	fetchViews as apiFetchViews,
	saveView as apiSaveView,
	deleteView as apiDeleteView,
	getViewCards as apiGetViewCards,
} from '../services/api.js'
import { MY_WORK_POLL_INTERVAL } from './queryKeys.js'

/**
 * Cross-board saved "Views" (#3815).
 *
 * A View is a named, board-agnostic saved filter persisted per-user in NC config.
 * Two concerns, two queries:
 *   - useViews():      the View DEFINITIONS ([{name, filter}]) that drive the nav
 *                      list and the editor. Small, per-user; kept mounted by the
 *                      nav so create/rename/delete reflect app-wide.
 *   - useViewCards():  the readable cross-board card feed (+ label/participant
 *                      catalogs) a View runs its client-side predicate over. Like
 *                      the My Work feeds, it is cross-board so no board delta poll
 *                      covers it - it refetches on focus and on a slow interval.
 *
 * The server is filter-agnostic: the feed is the same for every View; the CLIENT
 * applies each View's opaque predicate (via useBoardFilters' makePredicate).
 */
export function useViews() {
	return useQuery({
		queryKey: ['views'],
		queryFn: apiFetchViews,
		// Normalize to the flat list the UI consumes.
		select: (data) => data?.views ?? [],
		staleTime: 0,
	})
}

/**
 * The cross-board card feed a View filters over, plus the label / assignee facet
 * catalogs. Returns { cards, labels, participants }.
 */
export function useViewCards() {
	return useQuery({
		queryKey: ['view-cards'],
		queryFn: apiGetViewCards,
		select: (data) => ({
			cards: data?.cards ?? [],
			labels: data?.labels ?? [],
			participants: data?.participants ?? [],
		}),
		refetchOnWindowFocus: true,
		refetchOnMount: 'always',
		refetchInterval: MY_WORK_POLL_INTERVAL,
	})
}

/**
 * Create-or-rename (upsert-by-name) and delete mutations for View definitions.
 * Both refetch the definitions query so the nav list stays in step. The server
 * returns the full updated list; we seed the cache with it to avoid a round-trip.
 */
export function useViewMutations() {
	const queryClient = useQueryClient()

	const save = useMutation({
		mutationFn: ({ name, filter }) => apiSaveView(name, filter),
		onSuccess: (data) => {
			if (data?.views) queryClient.setQueryData(['views'], data)
		},
	})

	const remove = useMutation({
		mutationFn: (name) => apiDeleteView(name),
		onSuccess: (data) => {
			if (data?.views) queryClient.setQueryData(['views'], data)
		},
	})

	return { save, remove }
}

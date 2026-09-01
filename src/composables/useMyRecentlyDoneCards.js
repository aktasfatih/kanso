// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery } from '@tanstack/vue-query'
import { getMyRecentlyDoneCards as apiGetMyRecentlyDoneCards } from '../services/api.js'

/**
 * Key for the opt-in "recently done" feed (#10061).
 *
 * Deliberately NOT a child of ['my-cards']. Two consequences that are the
 * whole point:
 *  - it is outside MY_WORK_QUERY_KEYS, so the realtime funnel and the
 *    mutation settle phase never refetch it. It is a snapshot the user asked
 *    for, not a live feed.
 *  - it is outside offlineCache's PERSIST_PREFIXES, so it is never restored
 *    from IndexedDB. On-demand data has no business being pre-warmed.
 *
 * @type {[string]}
 */
export const MY_RECENTLY_DONE_QUERY_KEY = ['my-recently-done']

/**
 * Composable for the recently-completed half of "My tasks" (#10061).
 *
 * The product requirement is that this "shouldn't list everything unless we
 * ask for it", so the query is DISABLED until the caller flips `enabled` —
 * expanding the section is what issues the request. The default My Tasks page
 * load must stay exactly one query (the open feed), and this is the mechanism
 * that guarantees it.
 *
 * No refetchInterval, no refetchOnWindowFocus, no refetchOnMount: this is a
 * bounded, on-demand read, and every one of those would turn "loaded when I
 * ask" back into background traffic. Collapsing and re-expanding inside the
 * global staleTime therefore serves the cached rows and issues nothing.
 *
 * @param {import('vue').Ref<boolean>|boolean} enabled - true once the user has expanded the section
 * @return {object} the TanStack Query result
 */
export function useMyRecentlyDoneCards(enabled) {
	return useQuery({
		queryKey: MY_RECENTLY_DONE_QUERY_KEY,
		queryFn: apiGetMyRecentlyDoneCards,
		enabled,
		refetchOnWindowFocus: false,
		refetchOnMount: false,
	})
}

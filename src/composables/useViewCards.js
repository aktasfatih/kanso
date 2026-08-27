// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, unref } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { getViewCards as apiGetViewCards } from '../services/api.js'
import { MY_WORK_POLL_INTERVAL, VIEW_CARDS_QUERY_KEY } from './queryKeys.js'

/**
 * The cross-board card feed a View renders over (#3815): enriched card
 * summaries from every board the user can read (ACL enforced server-side). The
 * feed is board-agnostic and shared by every View - a View differs only in the
 * client-side filter + group-by it applies over this one dataset - so it lives
 * under a single query key, warmed once and reused across views.
 *
 * Like the My Work feeds it is cross-board (no board delta poll covers it), so
 * refetchOnMount 'always' + a focus/interval refetch keep it current - and,
 * because the card detail opens as an overlay ON the View (never blurring the
 * window, so focus refetch never fires there), card mutations invalidate
 * VIEW_CARDS_QUERY_KEY from their settle phase via invalidateCrossBoardFeeds.
 *
 * `data` is the server envelope `{ cards, capped, total, limit }` - `cards` is
 * hard-capped server-side to bound this single unbounded feed; `capped`/`total`
 * let the surface honestly report when it shows only the first N of M cards.
 *
 * The View's SORT is the one part of a View the server acts on: it is applied
 * before that cap (so a sorted View starts at the true first row, not the first
 * row of an arbitrary window), which makes the ordering part of the cache
 * identity - hence the sort mode + direction on the query key. Changing the sort
 * therefore refetches; `placeholderData` keeps the previous rows on screen for
 * that beat instead of flashing the loading state.
 *
 * @param {object|import('vue').Ref<object>} [sort] the active `{ mode, dir }`
 */
export function useViewCards(sort) {
	const sortMode = computed(() => unref(sort)?.mode ?? 'default')
	const sortDir = computed(() => unref(sort)?.dir ?? 'asc')
	return useQuery({
		queryKey: [...VIEW_CARDS_QUERY_KEY, sortMode, sortDir],
		queryFn: () => apiGetViewCards({ sortMode: sortMode.value, sortDir: sortDir.value }),
		placeholderData: (previous) => previous,
		refetchOnWindowFocus: true,
		refetchOnMount: 'always',
		refetchInterval: MY_WORK_POLL_INTERVAL,
	})
}

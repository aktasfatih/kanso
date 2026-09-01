// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery } from '@tanstack/vue-query'
import { getMyCards as apiGetMyCards } from '../services/api.js'
import { MY_WORK_POLL_INTERVAL } from './queryKeys.js'

/**
 * Composable for the "My tasks" feed - all open cards assigned to the current
 * user, across every board they can read. Refetches on window focus so the
 * panel stays current without a dedicated realtime channel.
 *
 * Data shape: { cards, truncated, limit } - the feed is capped server-side and
 * the cap is reported, so callers can tell a complete feed from a first window
 * (see services/myCardsFeed.js). Read it through `myCardsFeed(data)`, which
 * also covers the undefined-while-loading case.
 *
 * refetchOnMount 'always' (#3766): the nav badges keep this query mounted for
 * the app's lifetime, so it is often within the global staleTime when the user
 * navigates to My Tasks - a default mount refetch would be suppressed and the
 * page would render pre-mutation data. 'always' makes every view mount (i.e.
 * client-side navigation) revalidate against the server.
 *
 * refetchInterval (#3768): other users' changes while the user SITS on the
 * page (no navigation, no focus change) only arrive by polling - the feed is
 * cross-board, so no board delta poll covers it. The same interval keeps the
 * nav badge live from any view. Paused on hidden tabs (TanStack default).
 */
export function useMyCards() {
	return useQuery({
		queryKey: ['my-cards'],
		queryFn: apiGetMyCards,
		refetchOnWindowFocus: true,
		refetchOnMount: 'always',
		refetchInterval: MY_WORK_POLL_INTERVAL,
	})
}

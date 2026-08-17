// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery } from '@tanstack/vue-query'
import { getViewCards as apiGetViewCards } from '../services/api.js'
import { MY_WORK_POLL_INTERVAL } from './queryKeys.js'

/**
 * The cross-board card feed a View renders over (#3815): enriched card
 * summaries from every board the user can read (ACL enforced server-side). The
 * feed is board-agnostic and shared by every View - a View differs only in the
 * client-side filter + group-by it applies over this one dataset - so it lives
 * under a single query key, warmed once and reused across views.
 *
 * Like the My Work feeds it is cross-board (no board delta poll covers it), so
 * refetchOnMount 'always' + a focus/interval refetch keep it current.
 */
export function useViewCards() {
	return useQuery({
		queryKey: ['view-cards'],
		queryFn: apiGetViewCards,
		refetchOnWindowFocus: true,
		refetchOnMount: 'always',
		refetchInterval: MY_WORK_POLL_INTERVAL,
	})
}

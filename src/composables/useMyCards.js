// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery } from '@tanstack/vue-query'
import { getMyCards as apiGetMyCards } from '../services/api.js'

/**
 * Composable for the "My tasks" feed - all open cards assigned to the current
 * user, across every board they can read. Refetches on window focus so the
 * panel stays current without a dedicated realtime channel.
 */
export function useMyCards() {
	return useQuery({
		queryKey: ['my-cards'],
		queryFn: apiGetMyCards,
		refetchOnWindowFocus: true,
	})
}

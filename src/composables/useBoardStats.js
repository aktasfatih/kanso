// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { getBoardStats } from '../services/api.js'

/**
 * Fetch analytics stats for a single board.
 *
 * @param {import('vue').Ref<string>|string} boardId
 */
export function useBoardStats(boardId) {
	const resolvedId = computed(() =>
		typeof boardId === 'object' && boardId !== null ? boardId.value : boardId,
	)

	const query = useQuery({
		queryKey: computed(() => ['board-stats', resolvedId.value]),
		queryFn: () => getBoardStats(resolvedId.value),
		enabled: computed(() => !!resolvedId.value),
		// Stats snapshot — no need to poll aggressively; stale after 2 min
		staleTime: 2 * 60 * 1000,
	})

	return query
}

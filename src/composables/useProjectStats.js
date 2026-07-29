// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { getProjectStats } from '../services/api.js'

/**
 * Fetch cross-board analytics for a single project (owner-only). Mirrors
 * {@link useBoardStats}; the DTO omits board-specific panels (byStack, estimate
 * totals) and never sums points across mixed estimate scales.
 *
 * @param {import('vue').Ref<string>|string} projectId
 */
export function useProjectStats(projectId) {
	const resolvedId = computed(() =>
		typeof projectId === 'object' && projectId !== null ? projectId.value : projectId,
	)

	return useQuery({
		queryKey: computed(() => ['project-stats', resolvedId.value]),
		queryFn: () => getProjectStats(resolvedId.value),
		enabled: computed(() => !!resolvedId.value),
		// Analytics snapshot — stale after 2 min, same as board stats.
		staleTime: 2 * 60 * 1000,
	})
}

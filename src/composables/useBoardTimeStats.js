// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, toValue } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { getBoardTimeStats } from '../services/api.js'

export function useBoardTimeStats(boardId, range, enabled) {
	return useQuery({
		queryKey: computed(() => ['board-time-stats', String(toValue(boardId)), toValue(range).from, toValue(range).to]),
		queryFn: ({ queryKey }) => getBoardTimeStats(queryKey[1], queryKey[2], queryKey[3]),
		enabled: computed(() => !!toValue(boardId) && !!toValue(enabled)),
		staleTime: 0,
	})
}

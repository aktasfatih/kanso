/**
 * SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { fetchBoardAttachments } from '../services/api.js'

/**
 * Every attachment on a board the viewer may see (#10670), for the board-wide
 * attachments view. The server scopes the listing to the caller's card
 * visibility and hard-caps the page, so this is metadata only: {items, total,
 * capped}.
 *
 * Deliberately NOT part of the board payload - board endpoints return card
 * summaries only, and this is fetched when the view is actually opened.
 *
 * @param {number|string|import('vue').Ref} boardId board id (may be a ref)
 * @return {object} the TanStack query
 */
export function useBoardAttachments(boardId) {
	const resolvedId = computed(() =>
		typeof boardId === 'object' && boardId !== null ? boardId.value : boardId)

	return useQuery({
		queryKey: computed(() => ['board-attachments', resolvedId.value]),
		queryFn: () => fetchBoardAttachments(resolvedId.value),
		enabled: computed(() => !!resolvedId.value),
		staleTime: 30 * 1000,
	})
}

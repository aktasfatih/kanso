/**
 * SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed } from 'vue'
import { useInfiniteQuery } from '@tanstack/vue-query'
import { fetchBoardAttachments } from '../services/api.js'
import { boardAttachmentsQueryKey } from './queryKeys.js'

/**
 * How many rows one page of the board attachment listing asks for.
 *
 * Deliberately well UNDER the server's own hard cap
 * ({@see \OCA\Kanso\Service\CardAttachmentService::BOARD_PAGE_LIMIT}, 200): the
 * modal is a bounded-height scroller, so a first paint of 25 rows already fills
 * it, and every further page is one more indexed query rather than a 200-row
 * response nobody scrolled to. The cap stays the ceiling of a single request;
 * this is the size of a normal one.
 *
 * @type {number}
 */
export const BOARD_ATTACHMENTS_PAGE_SIZE = 25

/**
 * Every attachment on a board the viewer may see (#10670), for the board-wide
 * attachments view. The server scopes the listing to the caller's card
 * visibility and answers ONE page: {items, total, capped}.
 *
 * Deliberately NOT part of the board payload - board endpoints return card
 * summaries only, and this is fetched when the view is actually opened.
 *
 * PAGED (#10738), because the server always was: before this the client asked
 * once and stopped at the cap, so an attachment past it had no route in the UI
 * at all. `fetchNextPage()` walks the offsets; `capped` on the last page is the
 * server's own "there is more", so the client never has to guess.
 *
 * Offsets, not cursors: the listing is ordered by (created_at DESC, id DESC)
 * over a set that only grows at the FRONT, so a file uploaded while the modal
 * is open shifts later pages by one - it can repeat a row across pages, never
 * skip one silently. Anything stronger would mean a keyset cursor through the
 * visibility-scoped query for a modal that is open for seconds; the page ids
 * are the list's `:key`, so a repeat renders once.
 *
 * `staleTime` stays at 30s ON PURPOSE. The stale window was never the bug -
 * nothing invalidated this key at all, so the card mutations that add and
 * remove these very rows left the modal serving the old list until a reload.
 * They now invalidate {@see boardAttachmentsQueryKey} (useCardAttachments), and
 * invalidation refetches the loaded pages regardless of staleness.
 *
 * @param {number|string|import('vue').Ref} boardId board id (may be a ref)
 * @return {object} the TanStack infinite query
 */
export function useBoardAttachments(boardId) {
	const resolvedId = computed(() =>
		typeof boardId === 'object' && boardId !== null ? boardId.value : boardId)

	return useInfiniteQuery({
		queryKey: computed(() => boardAttachmentsQueryKey(resolvedId.value)),
		queryFn: ({ pageParam }) => fetchBoardAttachments(resolvedId.value, {
			limit: BOARD_ATTACHMENTS_PAGE_SIZE,
			offset: pageParam,
		}),
		initialPageParam: 0,
		// The next offset is how many rows are already in hand. `capped` means the
		// server has more BEYOND this page for THIS viewer (it is computed from the
		// viewer-scoped total), so paging can never walk past the visibility rule.
		// An empty page ends the walk even if `capped` says otherwise, so a total
		// that drifted under a concurrent delete cannot loop.
		getNextPageParam: (lastPage, allPages) => {
			if (!lastPage?.capped || !(lastPage.items?.length > 0)) {
				return undefined
			}
			return allPages.reduce((n, page) => n + (page.items?.length ?? 0), 0)
		},
		enabled: computed(() => !!resolvedId.value),
		staleTime: 30 * 1000,
	})
}

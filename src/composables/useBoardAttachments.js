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
 * visibility-scoped query for a modal that is open for seconds; the repeat is
 * deduped by attachment id in `items` below, because Vue does NOT dedupe by
 * `:key` - a duplicate key renders the row twice and warns.
 *
 * `staleTime` stays at 30s ON PURPOSE. The stale window was never the bug -
 * nothing invalidated this key at all, so the card mutations that add and
 * remove these very rows left the modal serving the old list until a reload.
 * They now invalidate {@see boardAttachmentsQueryKey} (useCardAttachments), and
 * invalidation refetches the loaded pages regardless of staleness.
 *
 * @param {number|string|import('vue').Ref} boardId board id (may be a ref)
 * @return {object} the TanStack infinite query, plus `items` (the deduped rows
 *   to render), `total` and `loadError` - see below for why the last two are
 *   derived HERE rather than in the modal
 */
export function useBoardAttachments(boardId) {
	const resolvedId = computed(() =>
		typeof boardId === 'object' && boardId !== null ? boardId.value : boardId)

	const query = useInfiniteQuery({
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

	const pages = computed(() => query.data.value?.pages ?? [])

	// The rows to render: every page so far, in order, with repeats dropped.
	//
	// Paging by offset over a list that grows at the FRONT means a file uploaded
	// between two page fetches comes back as both the last row of page N and the
	// first of page N+1 (see the offsets note above). Vue does NOT dedupe by
	// `:key` - a duplicate key logs a warning, renders the row TWICE and can
	// patch the wrong one - so the id is deduped here, first occurrence winning,
	// which leaves the pages in the order the server sent them.
	const items = computed(() => {
		const seen = new Set()
		const rows = []
		for (const page of pages.value) {
			for (const item of page.items ?? []) {
				if (seen.has(item.id)) {
					continue
				}
				seen.add(item.id)
				rows.push(item)
			}
		}
		return rows
	})

	// The freshest total is the last page's - each page carries the
	// viewer-scoped count as of its own query.
	const total = computed(() =>
		pages.value.length ? (pages.value[pages.value.length - 1].total ?? 0) : 0)

	// `error` is set by ANY failed fetch, a second page included, while the pages
	// already fetched stay in `data`. A view that renders its error state off
	// `error` therefore throws away a screenful of rows the reader was using the
	// moment one "Load more" fails. This is the narrower question the whole-list
	// error state should ask: is there nothing to show? A failed next page is
	// `isFetchNextPageError` instead, reported next to the button that caused it.
	const loadError = computed(() => !!query.error.value && pages.value.length === 0)

	return { ...query, pages, items, total, loadError }
}

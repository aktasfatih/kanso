// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, ref } from 'vue'
import { useMyReviews } from './useMyReviews.js'
import { useInbox } from './useInbox.js'
import { useMyCards } from './useMyCards.js'

/**
 * localStorage key holding the unix-seconds timestamp of the newest inbox item
 * the user has already seen. Anything newer than this counts as "new" and is
 * reflected in the Inbox nav badge until the user opens the Inbox view.
 */
const INBOX_SEEN_KEY = 'kanso.inbox.lastSeenAt'

function readInboxSeenAt() {
	try {
		const raw = localStorage.getItem(INBOX_SEEN_KEY)
		const n = raw === null ? 0 : Number(raw)
		return Number.isFinite(n) ? n : 0
	} catch {
		return 0
	}
}

// Reactive mirror of the stored "last seen" timestamp so badges recompute the
// moment the Inbox view marks activity as seen (same tab).
const inboxSeenAt = ref(readInboxSeenAt())

/**
 * Mark the inbox as seen up to (and including) the newest currently-known item.
 * Called by the Inbox view on mount so its nav badge clears once visited.
 * @param {number} newestCreatedAt - unix seconds of the newest inbox item (0 if empty)
 */
export function markInboxSeen(newestCreatedAt) {
	const value = Number(newestCreatedAt) || 0
	if (value <= inboxSeenAt.value) return
	inboxSeenAt.value = value
	try {
		localStorage.setItem(INBOX_SEEN_KEY, String(value))
	} catch {
		/* localStorage unavailable — badge falls back to session-only state */
	}
}

/**
 * Lightweight badge counts for the My Tasks / My Reviews / Inbox nav items.
 *
 * Reuses the existing feed queries (`useMyCards`, `useMyReviews`, `useInbox`)
 * from the shared query cache — no extra endpoints or polling. The nav mounts
 * once for the app's lifetime, so these queries are warmed here and shared with
 * the views themselves (TanStack Query dedupes by key).
 *
 * - tasks:   count of open cards assigned to me (useMyCards already returns
 *            open-only, so no extra filtering is applied here).
 * - reviews: review requests awaiting my verdict (state === 'pending').
 * - inbox:   activity items newer than the last time I opened the Inbox.
 */
export function useMyWorkBadges() {
	const { data: cardsData } = useMyCards()
	const { data: reviewsData } = useMyReviews()
	const { data: inboxData } = useInbox()

	const tasksCount = computed(() => (cardsData.value ?? []).length)

	const reviewsCount = computed(() =>
		(reviewsData.value ?? []).filter((r) => r.state === 'pending').length,
	)

	const inboxCount = computed(() =>
		(inboxData.value ?? []).filter((item) => Number(item.createdAt) > inboxSeenAt.value).length,
	)

	return { tasksCount, reviewsCount, inboxCount }
}

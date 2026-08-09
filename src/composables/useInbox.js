// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { useQuery } from '@tanstack/vue-query'
import { getInbox as apiGetInbox } from '../services/api.js'

/**
 * Composable for the Inbox feed - recent comments on cards the current user
 * watches, posted by other people.  Newest-first, server-filtered.
 *
 * Each item: { id, cardId, boardId, cardTitle, boardTitle, author,
 *              authorDisplayName?, body, createdAt }
 */
export function useInbox() {
	const query = useQuery({
		queryKey: ['inbox'],
		queryFn: apiGetInbox,
		// #3766: the nav badges keep this query permanently mounted, so a default
		// mount refetch is suppressed within staleTime - 'always' makes every
		// navigation to the Inbox revalidate against the server.
		refetchOnMount: 'always',
	})

	return query
}

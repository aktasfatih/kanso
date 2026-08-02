// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * useTrash - fetch / restore / purge soft-deleted cards for a board.
 *
 * The trash list is NOT part of the board payload - it is fetched separately
 * from GET /api/boards/{id}/trash only when the trash view is active (enabled).
 *
 * Mutation strategy:
 *   restore - POST /api/cards/{id}/restore → card reappears on the board.
 *             On settled: invalidate trash query + board query so the card
 *             shows up in the stack column and is removed from the panel.
 *   purge   - DELETE /api/cards/{id}/purge → permanent destruction.
 *             On settled: invalidate trash query only (card never returns).
 *
 * Optimistic removal from the trash list is wired for restore so the item
 * disappears instantly; on error it rolls back via invalidation.
 */

import { computed } from 'vue'
import { useQuery, useMutation, useQueryClient } from '@tanstack/vue-query'
import { fetchTrash, restoreCard, purgeCard } from '../services/api.js'
import { boardQueryKey } from './queryKeys.js'

/**
 * @param {import('vue').Ref<string|number>} boardId  - reactive board id
 * @param {import('vue').Ref<boolean>}       enabled  - only fetch when true (panel open)
 */
export function useTrash(boardId, enabled) {
	const queryClient = useQueryClient()

	// Derive the trash query key so it is consistent across the composable.
	const trashKey = computed(() => ['trash', String(boardId.value)])

	// ── Query ─────────────────────────────────────────────────────────────────
	const trashQuery = useQuery({
		queryKey: trashKey,
		queryFn: () => fetchTrash(boardId.value),
		// Only run when the panel is open - avoids a cold fetch on every board load.
		enabled,
		staleTime: 0,
	})

	// ── Restore ──────────────────────────────────────────────────────────────
	const restore = useMutation({
		mutationFn: (cardId) => restoreCard(cardId),

		onMutate: async (cardId) => {
			const key = trashKey.value
			await queryClient.cancelQueries({ queryKey: key })
			const previousTrash = queryClient.getQueryData(key)

			// Optimistic: remove the card from the trash list immediately.
			queryClient.setQueryData(key, (old) => {
				if (!Array.isArray(old)) return old
				return old.filter((c) => c.id !== cardId)
			})

			return { previousTrash, key }
		},

		onError: (_err, _cardId, context) => {
			// Roll back to the snapshot so the user can retry.
			if (context?.previousTrash !== undefined) {
				queryClient.setQueryData(context.key, context.previousTrash)
			}
		},

		onSettled: (_data, _err, _cardId) => {
			// Revalidate both caches: trash list to reflect server truth,
			// board cache so the restored card appears in the stack column.
			queryClient.invalidateQueries({ queryKey: trashKey.value })
			queryClient.invalidateQueries({ queryKey: boardQueryKey(boardId.value) })
		},
	})

	// ── Purge ────────────────────────────────────────────────────────────────
	const purge = useMutation({
		mutationFn: (cardId) => purgeCard(cardId),

		onMutate: async (cardId) => {
			const key = trashKey.value
			await queryClient.cancelQueries({ queryKey: key })
			const previousTrash = queryClient.getQueryData(key)

			// Optimistic: remove the card from the trash list immediately.
			queryClient.setQueryData(key, (old) => {
				if (!Array.isArray(old)) return old
				return old.filter((c) => c.id !== cardId)
			})

			return { previousTrash, key }
		},

		onError: (_err, _cardId, context) => {
			if (context?.previousTrash !== undefined) {
				queryClient.setQueryData(context.key, context.previousTrash)
			}
		},

		onSettled: () => {
			// Trash list must revalidate; the board query is unaffected (card
			// was already absent from it, having been soft-deleted earlier).
			queryClient.invalidateQueries({ queryKey: trashKey.value })
		},
	})

	return {
		trashQuery,
		restore,
		purge,
	}
}

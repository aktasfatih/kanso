// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { boardQueryKey } from '../composables/queryKeys.js'

function hasFreshBoardCard(queryClient, boardId, cardId) {
	if (!queryClient || queryClient.isMutating() > 0) return false
	const query = queryClient.getQueryCache().find({ queryKey: boardQueryKey(boardId), exact: true })
	if (!query || query.state.status !== 'success' || query.state.isInvalidated || query.state.fetchStatus !== 'idle') return false
	const staleTime = typeof query.options.staleTime === 'function'
		? query.options.staleTime(query)
		: query.options.staleTime
	if (query.isStaleByTime(staleTime ?? 0)) return false
	const board = query.state.data
	return String(board?.board?.id) === String(boardId)
		&& Array.isArray(board?.cards)
		&& board.cards.some((card) => String(card.id) === String(cardId)
			&& String(card.boardId) === String(boardId) && !(Number(card.deletedAt) > 0))
}

export async function resolveCardRoute(to, from, queryClient, resolveCardNavigation) {
	if (!['card-modal', 'card-page'].includes(to.name) || !/^\d+$/.test(String(to.params.cardId))) return
	if (to.name === 'card-modal' && from?.name === 'board' && String(from.params?.id) === String(to.params.id)
		&& hasFreshBoardCard(queryClient, to.params.id, to.params.cardId)) return
	try {
		const resolved = await resolveCardNavigation(Number(to.params.cardId))
		if (String(resolved.cardId) === String(to.params.cardId)) return
		return to.name === 'card-modal'
			? { name: 'card-modal', params: { id: resolved.boardId, cardId: resolved.cardId }, query: to.query, hash: to.hash, replace: true }
			: { name: 'card-page', params: { cardId: resolved.cardId }, query: to.query, hash: to.hash, replace: true }
	} catch {
		// Let the normal page handle missing or inaccessible cards.
	}
}

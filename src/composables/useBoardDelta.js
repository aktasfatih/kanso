// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { fetchBoardChanges } from '../services/api.js'
import { isBoardMovePending } from './useCardMove.js'
import { boardQueryKey } from './queryKeys.js'

/**
 * Board delta-sync (#3675). A single change no longer re-downloads the whole
 * board: the client polls `GET /api/boards/{id}/changes?since=<cursor>` and
 * PATCHES the TanStack cache with only the touched cards/stacks - O(delta), not
 * O(boardSize).
 *
 * Cursor lifecycle (database-first, per the charter's state pattern):
 *  - seedCursor() is called with `data.cursor` on every full fetchBoard, so the
 *    cursor is always re-seeded from the server's source of truth.
 *  - syncBoardDelta() advances the cursor only AFTER a successful cache patch.
 *  - on resync (server says the delta is unusable) or any error, the cursor is
 *    dropped and a full board invalidate re-seeds it. The query's own slow 60s
 *    refetchInterval is the belt-and-suspenders that self-heals any missed delta.
 *
 * Mid-drag safety: never patch while a move is pending for the board - a patch
 * (like a refetch) would clobber the optimistic move placement. The move queue's
 * drain invalidate (useCardMove) is the post-drag reconciliation point.
 */

// Module-level cursor registry, keyed by String(boardId) (mirrors
// useCardMove's pendingByBoard). Shared across every consumer of the board
// cache (the useBoard poll and the main.js push handler) so they advance the
// same cursor.
const cursorByBoard = new Map()

function keyOf(boardId) {
	const value = typeof boardId === 'object' && boardId !== null && boardId.value !== undefined
		? boardId.value
		: boardId
	return String(value)
}

/**
 * Seed / re-seed the board's cursor from a full board payload's `data.cursor`.
 * Called after every fetchBoard so a full refetch always resets the cursor to
 * the server's latest change id.
 *
 * @param {number|string|import('vue').Ref} boardId
 * @param {number} cursor the board's latest change id from the board payload
 */
export function seedCursor(boardId, cursor) {
	if (typeof cursor === 'number' && Number.isFinite(cursor)) {
		cursorByBoard.set(keyOf(boardId), cursor)
	}
}

/**
 * Drop the board's cursor - forces the next full fetchBoard to re-seed it.
 *
 * @param {number|string|import('vue').Ref} boardId
 */
export function dropCursor(boardId) {
	cursorByBoard.delete(keyOf(boardId))
}

/**
 * Immutably apply a `{upsert, remove}` delta to an array of entities keyed by
 * `id`: upsert replaces-by-id-or-appends, remove filters out the given ids.
 *
 * @param {Array<object>} list the current cached entities
 * @param {{upsert?: Array<object>, remove?: Array<number>}} delta
 * @return {Array<object>} a new array (never mutated in place)
 */
function applyDelta(list, delta) {
	const current = Array.isArray(list) ? list : []
	const upsert = delta?.upsert ?? []
	const removeIds = new Set(delta?.remove ?? [])

	const byId = new Map(current.map((e) => [e.id, e]))
	for (const entity of upsert) {
		byId.set(entity.id, entity)
	}
	const next = []
	for (const entity of byId.values()) {
		if (!removeIds.has(entity.id)) {
			next.push(entity)
		}
	}
	return next
}

/**
 * Fetch and apply the board delta since the client's cursor, patching the
 * TanStack cache in place. A no-op when there's no cursor yet (a full fetch
 * hasn't seeded one), when a move is pending (never patch mid-drag), or when
 * there's no cached board to patch. On `resync` or any error it drops the cursor
 * and invalidates the board query (a full refetch re-seeds the cursor).
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 * @param {number|string|import('vue').Ref} boardId
 * @return {Promise<void>}
 */
export async function syncBoardDelta(queryClient, boardId) {
	if (isBoardMovePending(boardId)) {
		return
	}
	const key = keyOf(boardId)
	const since = cursorByBoard.get(key)
	if (since === undefined) {
		// No cursor yet: let the query's own fetch seed one instead of guessing.
		return
	}
	const boardKey = boardQueryKey(boardId)

	let delta
	try {
		delta = await fetchBoardChanges(key, since)
	} catch {
		// A failed delta read must never leave the client silently stale: drop the
		// cursor and fall back to a full refetch (which re-seeds the cursor).
		dropCursor(boardId)
		queryClient.invalidateQueries({ queryKey: boardKey })
		return
	}

	if (delta?.resync) {
		dropCursor(boardId)
		queryClient.invalidateQueries({ queryKey: boardKey })
		return
	}

	// A move may have STARTED while the delta was in flight - re-check before
	// patching so we never clobber an optimistic placement mid-drag.
	if (isBoardMovePending(boardId)) {
		return
	}

	const existing = queryClient.getQueryData(boardKey)
	if (!existing) {
		// Nothing cached to patch (board not loaded / evicted): just record the
		// advanced cursor; a future full fetch re-seeds from the payload anyway.
		cursorByBoard.set(key, delta.cursor)
		return
	}

	queryClient.setQueryData(boardKey, (old) => {
		if (!old) return old
		return {
			...old,
			cards: applyDelta(old.cards, delta.cards),
			stacks: applyDelta(old.stacks, delta.stacks),
		}
	})

	// Advance the cursor only after a successful patch.
	cursorByBoard.set(key, delta.cursor)
}

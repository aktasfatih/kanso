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
 *
 * Open-card freshness (#3767): the board delta only patches the board SUMMARY
 * cache. The card modal composes separate per-card detail queries (description,
 * comments, checklist, links, attachments, time entries, activity) that a
 * summary patch never touches - so an open modal in another tab went stale
 * until closed and reopened. Every card-scoped mutation (comments, checklist,
 * reviews, labels, relations included) records an ENTITY_CARD change row, so a
 * delta upsert/remove for a card id is exactly the "this card's details may
 * have changed" signal: when one arrives we invalidate that card's detail
 * queries. Only ACTIVE queries refetch (an open modal); everything else is just
 * marked stale for its next mount, so this is O(open modals), not O(cards).
 *
 * Draft safety: the modal's title/description editors copy into local draft
 * refs when editing starts, so a detail refetch can never clobber a dirty
 * editor. Optimistic in-flight mutations ARE clobberable, so the invalidation
 * defers while any mutation is in flight (see flushCardDetailInvalidation).
 */

// Module-level cursor registry, keyed by String(boardId) (mirrors
// useCardMove's pendingByBoard). Shared across every consumer of the board
// cache (the useBoard poll and the main.js push handler) so they advance the
// same cursor.
const cursorByBoard = new Map()

// First segment of every per-card detail query key the modal (and the tile
// quick-preview) composes. Board-level keys ('board', 'board-stats', ...) are
// deliberately absent - the delta patch / board invalidate already covers them.
const CARD_DETAIL_KEY_PREFIXES = new Set([
	'card',
	'comments',
	'checklist',
	'card-links',
	'card-attachments',
	'card-time-entries',
	'card-activity',
])

// "Changes arrived" client signal (#3767): listeners are notified after a
// delta has been applied (or a resync/error forced a full refetch), with the
// card ids the window touched. Kept deliberately tiny - consumers that need
// finer-than-board realtime (open modal freshness today, presence/toasts
// tomorrow) subscribe here instead of growing new polling loops.
const changesAppliedListeners = new Set()

/**
 * Subscribe to applied board changes. The listener receives
 * `{ boardId: string, cardIds: number[], resync: boolean }` - on a resync the
 * touched ids are unknown, so `cardIds` is empty and `resync` is true.
 *
 * @param {(event: {boardId: string, cardIds: number[], resync: boolean}) => void} listener
 * @return {() => void} unsubscribe
 */
export function onBoardChangesApplied(listener) {
	changesAppliedListeners.add(listener)
	return () => changesAppliedListeners.delete(listener)
}

/**
 * @param {{boardId: string, cardIds: number[], resync: boolean}} event
 */
function emitBoardChangesApplied(event) {
	for (const listener of changesAppliedListeners) {
		try {
			listener(event)
		} catch {
			// A listener must never be able to break delta sync itself.
		}
	}
}

// Pending detail invalidation (#3767). Invalidation is deferred while ANY
// mutation is in flight: an invalidate-triggered refetch racing an optimistic
// patch (comment add, checklist toggle) could momentarily resurrect the
// pre-mutation server state - the exact clobbering the onMutate cancelQueries
// convention exists to prevent. The ids are remembered (not dropped) and
// flushed by the next syncBoardDelta tick; the mutation's own onSettled
// invalidation covers its card in the meantime. `all` is the resync/error
// case where the touched ids are unknown.
const pendingDetailInvalidation = { all: false, ids: new Set() }

/**
 * Queue (and, when safe, immediately apply) an invalidation of the per-card
 * detail queries for the given card ids - or for every cached card when
 * `cardIds` is null (resync/error: the touched ids are unknown).
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 * @param {Array<number>|null} cardIds
 */
function invalidateCardDetailQueries(queryClient, cardIds) {
	if (cardIds === null) {
		pendingDetailInvalidation.all = true
	} else {
		for (const id of cardIds) {
			pendingDetailInvalidation.ids.add(String(id))
		}
	}
	flushCardDetailInvalidation(queryClient)
}

/**
 * Apply any queued detail invalidation, unless a mutation is in flight (the
 * queue is kept and retried on the next delta tick). Matching is by predicate
 * so both String- and Number-typed detail keys (['card', '7'] from the route
 * param, ['card', 7] from a tile preview) are caught.
 *
 * @param {import('@tanstack/vue-query').QueryClient} queryClient
 */
function flushCardDetailInvalidation(queryClient) {
	if (!pendingDetailInvalidation.all && pendingDetailInvalidation.ids.size === 0) {
		return
	}
	if (queryClient.isMutating() > 0) {
		return
	}
	const all = pendingDetailInvalidation.all
	const ids = new Set(pendingDetailInvalidation.ids)
	pendingDetailInvalidation.all = false
	pendingDetailInvalidation.ids.clear()
	queryClient.invalidateQueries({
		predicate: (query) => {
			const [prefix, id] = query.queryKey
			if (!CARD_DETAIL_KEY_PREFIXES.has(prefix)) {
				return false
			}
			return all || ids.has(String(id))
		},
	})
}

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
	// Retry any detail invalidation a previous tick deferred (mutation was in
	// flight). Runs even when the delta below turns out empty or unfetchable -
	// the queue must drain, not depend on new changes arriving.
	flushCardDetailInvalidation(queryClient)

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
		// The full board refetch only heals the SUMMARY cache; the rows we may
		// have missed could carry open-card detail changes, so invalidate the
		// detail queries too (ids unknown → all).
		dropCursor(boardId)
		queryClient.invalidateQueries({ queryKey: boardKey })
		invalidateCardDetailQueries(queryClient, null)
		emitBoardChangesApplied({ boardId: key, cardIds: [], resync: true })
		return
	}

	if (delta?.resync) {
		// Same reasoning as the error path: a resync window (saturated, pruned
		// tail, or an unmodeled entity kind) may hide ENTITY_CARD rows for the
		// open card - never let it stay stale.
		dropCursor(boardId)
		queryClient.invalidateQueries({ queryKey: boardKey })
		invalidateCardDetailQueries(queryClient, null)
		emitBoardChangesApplied({ boardId: key, cardIds: [], resync: true })
		return
	}

	// A move may have STARTED while the delta was in flight - re-check before
	// patching so we never clobber an optimistic placement mid-drag.
	if (isBoardMovePending(boardId)) {
		return
	}

	// The card ids this window touched: upserts (edited/created, incl. every
	// card-scoped sub-entity change - comments, checklist, reviews, labels -
	// which all record an ENTITY_CARD row) plus removes (deleted/hidden).
	const touchedCardIds = [
		...(delta.cards?.upsert ?? []).map((card) => card.id),
		...(delta.cards?.remove ?? []),
	]
	// An empty delta (most poll ticks) is not a change event - don't spam
	// listeners with it.
	const hasChanges = touchedCardIds.length > 0
		|| (delta.stacks?.upsert?.length ?? 0) > 0
		|| (delta.stacks?.remove?.length ?? 0) > 0

	const existing = queryClient.getQueryData(boardKey)
	if (!existing) {
		// Nothing cached to patch (board not loaded / evicted): just record the
		// advanced cursor; a future full fetch re-seeds from the payload anyway.
		// The rows are consumed here (the cursor moves past them), so the detail
		// invalidation must still happen or an open modal misses the change.
		invalidateCardDetailQueries(queryClient, touchedCardIds)
		cursorByBoard.set(key, delta.cursor)
		if (hasChanges) {
			emitBoardChangesApplied({ boardId: key, cardIds: touchedCardIds, resync: false })
		}
		return
	}

	queryClient.setQueryData(boardKey, (old) => {
		if (!old) return old
		return {
			...old,
			cards: applyDelta(old.cards, delta.cards),
			stacks: applyDelta(old.stacks, delta.stacks),
			// Dependency edges (#5896) are a whole-list replacement, not a
			// per-id delta: they aren't derivable from card summaries, and the
			// server only sends them when the window was non-empty (a relation
			// add/remove always puts ENTITY_CARD rows in it). Absent key = no
			// relation change rode this window, so keep what we have.
			...(Array.isArray(delta.blocksEdges) ? { blocksEdges: delta.blocksEdges } : {}),
		}
	})

	// Open-card freshness (#3767): a change row for a card means its DETAIL
	// data may have changed too - refetch the open modal's queries.
	invalidateCardDetailQueries(queryClient, touchedCardIds)

	// Advance the cursor only after a successful patch.
	cursorByBoard.set(key, delta.cursor)
	if (hasChanges) {
		emitBoardChangesApplied({ boardId: key, cardIds: touchedCardIds, resync: false })
	}
}

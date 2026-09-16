// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10293 — the move-pending guard syncBoardDelta re-checks AFTER the fetch.
//
// syncBoardDelta carries two copies of the same question. The ENTRY check
// ("is a move pending right now?") is pinned by pushLiveness.test.mjs: it is
// what stops a poll tick from going out mid-drag at all. The second one, after
// `await fetchBoardChanges`, answers a question the first one cannot: a drag
// that STARTS while the delta request is already on the wire. The window is
// real - /changes has no 304 path, so every tick is a full round trip, and a
// drag begun during one comes back to a payload that still describes the card's
// PRE-move placement. Applying it writes the server's stale stackId/sortKey over
// the optimistic patch the user is currently looking at, and the card jumps back
// under the cursor. (Performance bet 1 puts the placement in a single
// fractional sortKey on one row, so "clobber the placement" is exactly one
// assignment - there is no second source to heal it before the drain
// invalidate.)
//
// It was unpinned until this file, and unpinned in the specific way duplicated
// defensive checks always are: deleting it left all eight realtime tests green,
// because every one of them has a move pending BEFORE the tick starts, so the
// entry check refuses first and the post-fetch copy is never reached. The only
// way to reach it is to start the move from INSIDE the in-flight request, which
// is what the adapter below does.
//
// Rig follows cardMoveQueue.test.mjs: a `window` stub before any @nextcloud
// import, dynamic imports in that order, the real composable under
// app.runWithContext, and the transport stubbed at the axios ADAPTER - so the
// real move queue, the real optimistic patch, the real services/api.js call and
// the real syncBoardDelta run. No timers: syncBoardDelta is called directly,
// because the claim is about the function's own ordering, not the poll's.

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}

const { createApp, effectScope } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useCardMove, isBoardMovePending } = await import('../../src/composables/useCardMove.js')
const { syncBoardDelta, seedCursor } = await import('../../src/composables/useBoardDelta.js')
const { boardQueryKey } = await import('../../src/composables/queryKeys.js')

// The card's placement as the server still knows it, i.e. what a delta window
// fetched before the drag started carries.
const SERVER_PLACEMENT = { id: 1, stackId: 10, sortKey: 'a' }
// Where the user drops it. `optimisticKey` is the fractional sort key the drag
// computes client-side; the server's own key arrives with the move response.
const DROP_STACK = 20
const DROP_KEY = 'z'

const scopes = []
const clients = []
after(() => {
	for (const scope of scopes) scope.stop()
	for (const client of clients) {
		client.unmount()
		client.clear()
	}
})

/**
 * Let every already-resolved promise chain run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 20; i++) {
		await new Promise((resolve) => setImmediate(resolve))
	}
}

/**
 * @param {object} config the axios request config
 * @param {object} data the response body
 * @return {object} an axios-shaped response
 */
function respond(config, data) {
	return { status: 200, statusText: 'OK', data, headers: {}, config }
}

/**
 * A real move queue for `boardId` with its own QueryClient, one cached card at
 * its server placement, a seeded delta cursor (what fetchBoard's queryFn does
 * after a full read) and an axios adapter that serves `/changes` from `delta`
 * and parks every `/move` until releaseMove().
 *
 * `duringDelta` runs INSIDE the first /changes request, between "the request
 * went out" and "the response came back" - the only moment from which the
 * post-fetch guard is reachable.
 *
 * @param {number} boardId distinct per test; the cursor and pending registries are module-scoped
 * @param {object} options
 * @param {object} options.delta the /changes payload
 * @param {?Function} [options.duringDelta] run inside the first in-flight /changes
 * @return {object} the rig
 */
function harness(boardId, { delta, duringDelta = null }) {
	const app = createApp({})
	const queryClient = new QueryClient()
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	queryClient.setQueryData(boardQueryKey(boardId), {
		cards: [{ ...SERVER_PLACEMENT }],
		stacks: [],
	})
	seedCursor(boardId, 1)

	const sinceParams = []
	let unpark = null
	let interfered = false
	axios.defaults.adapter = async (config) => {
		if (config.url.includes('/move')) {
			// Parked, so the board stays move-pending for the whole test: the
			// guard under test only matters while the move has not landed.
			await new Promise((resolve) => { unpark = resolve })
			return respond(config, { id: 1, stackId: DROP_STACK, sortKey: 'm', lastModified: 2 })
		}
		if (config.url.includes('/changes')) {
			sinceParams.push(config.params?.since)
			if (duringDelta && !interfered) {
				interfered = true
				duringDelta()
			}
			return respond(config, delta)
		}
		return respond(config, { id: boardId, cards: [], stacks: [], cursor: 1, lastModified: 1 })
	}

	const scope = effectScope()
	scopes.push(scope)
	const move = app.runWithContext(() => scope.run(() => useCardMove(boardId)))

	return {
		queryClient,
		enqueueMove: move.enqueueMove,
		releaseMove: () => unpark?.(),
		deltaReads: () => sinceParams.length,
		sinceParams: () => sinceParams,
		interfered: () => interfered,
		card: () => queryClient.getQueryData(boardQueryKey(boardId))
			.cards.find((c) => c.id === 1),
	}
}

test('a drag that starts while the delta is in flight is not patched over by it', async () => {
	const boardId = 301
	// The window this delta describes was read before the drag existed, so it
	// still places the card where the server has it. Applying it is precisely
	// the clobber.
	const rig = harness(boardId, {
		delta: {
			cursor: 9,
			cards: { upsert: [{ ...SERVER_PLACEMENT }], remove: [] },
			stacks: { upsert: [], remove: [] },
		},
		duringDelta: () => {
			rig.enqueueMove({
				cardId: 1,
				targetStackId: DROP_STACK,
				afterCardId: null,
				optimisticKey: DROP_KEY,
			})
		},
	})

	await syncBoardDelta(rig.queryClient, boardId)
	await flush()

	// Anchors. Without these the assertions below could pass because the delta
	// never went out (entry check refused) or the drag never started.
	assert.equal(rig.deltaReads(), 1,
		'the delta must actually have been fetched: no move was pending when the '
		+ 'tick started, so the entry check has nothing to refuse and this test '
		+ 'would otherwise be measuring that guard instead')
	assert.ok(rig.interfered(), 'the drag must have started from inside the in-flight request')
	assert.ok(isBoardMovePending(boardId),
		'and must still be pending when the response lands - a move that already '
		+ 'settled is not what the post-fetch guard is about')

	assert.equal(rig.card().stackId, DROP_STACK,
		'the delta must not move the card back to its pre-drag stack: the payload '
		+ 'was read before the drag existed, so applying it writes stale placement '
		+ 'over the optimistic patch and the card jumps back under the cursor')
	assert.equal(rig.card().sortKey, DROP_KEY,
		'same for the fractional sort key - the whole placement lives in that one '
		+ 'string (performance bet 1), so clobbering it is one assignment and '
		+ 'nothing heals it until the queue drains')

	// The skipped window must not be LOST either: the cursor stays where it was,
	// so the next tick re-reads exactly the rows this one declined to apply.
	// Advancing it past unapplied rows would drop them silently until the 60s
	// safety-net refetch.
	rig.releaseMove()
	await flush()
	await syncBoardDelta(rig.queryClient, boardId)
	await flush()
	assert.deepEqual(rig.sinceParams(), [1, 1],
		'the declined window must be re-read, not skipped: advancing the cursor '
		+ 'past rows that were never applied loses them')
})

test('a move already pending when the tick starts is refused before any /changes read', async () => {
	// The entry check, asserted here in its own right because it is what makes
	// the push handler in main.js safe WITHOUT a pre-check of its own: the
	// handler hands every push frame straight to syncBoardDelta, and this is the
	// guard that refuses the mid-drag ones. (#10292 pinned the same check through
	// the poll; this pins it through a direct call, which is the shape the push
	// path uses.)
	const boardId = 302
	const rig = harness(boardId, {
		delta: {
			cursor: 1,
			cards: { upsert: [], remove: [] },
			stacks: { upsert: [], remove: [] },
		},
	})

	// Control: with nothing pending, a call really does hit /changes.
	await syncBoardDelta(rig.queryClient, boardId)
	await flush()
	assert.equal(rig.deltaReads(), 1, 'the rig must read /changes when no move is pending')

	rig.enqueueMove({
		cardId: 1,
		targetStackId: DROP_STACK,
		afterCardId: null,
		optimisticKey: DROP_KEY,
	})
	await flush()
	assert.ok(isBoardMovePending(boardId), 'the drag must be pending before the next call')

	await syncBoardDelta(rig.queryClient, boardId)
	await flush()
	assert.equal(rig.deltaReads(), 1,
		'no /changes request may go out while a move is pending - the push handler '
		+ 'relies on this refusal, it does not pre-check for itself')
	assert.equal(rig.card().stackId, DROP_STACK, 'and the optimistic placement stands')
})

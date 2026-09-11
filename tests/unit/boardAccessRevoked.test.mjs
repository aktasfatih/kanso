// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10385 — a board whose access is revoked while it is on screen must stop
// rendering; a board whose SERVER merely stumbled must not.
//
// A failed refetch does not take the payload away: TanStack's error reducer
// keeps `data` exactly as it was, and BoardView renders its stacks from `data`
// alone. So the 403 arrived, the error box appeared — with exactly the right
// copy, "This board no longer exists or you no longer have access." — and the
// revoked board went on rendering underneath it, cards and all, every write to
// which the server was by then refusing.
//
// The fix cannot be "any error blanks the board", and that is what this file
// exists to hold. A 403/404 is the server ANSWERING about access. A 500, or a
// dropped connection, is the server failing to answer, and the cached payload is
// the best thing the client has — blanking on those would turn a flaky network
// into an empty screen and undo both the offline cache and #10299's 304 path.
// Two of the six tests below are that half, and a third is the control.
//
// Rig is boardRefetchInterval.test.mjs's, for the same reasons: a `window` stub
// before any @nextcloud import, dynamic imports in that order, the real
// composable under app.runWithContext, and transport stubbed at the axios
// ADAPTER — so the real fetchBoard, the real TanStack observer and the real
// delta loop all run, and the failures arrive as real axios rejections (a status
// answer carries `response`, a transport failure does not, which is the whole
// distinction under test).

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}

const { createApp, effectScope, ref } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useBoard } = await import('../../src/composables/useBoard.js')
const { seedCursor } = await import('../../src/composables/useBoardDelta.js')
const { boardQueryKey } = await import('../../src/composables/queryKeys.js')

// Defined after the imports and deliberately not a DOM: TanStack's focusManager
// reads `document.visibilityState` when the interval fires, and useBoard's own
// poll reads it too, so it has to be present and 'visible'.
globalThis.document = {
	hidden: false,
	visibilityState: 'visible',
	addEventListener() {},
	removeEventListener() {},
}

// useBoard's delta cadence with no push proven (the fallback, and the one the
// dev/CI stack actually runs on).
const DELTA_CADENCE = 5_000

const clients = []
after(() => {
	for (const client of clients) {
		client.unmount()
		client.clear()
	}
})

/**
 * Resolve or reject a stubbed response the way axios's own adapters do.
 *
 * `validateStatus` is applied by the ADAPTER (axios's `settle`), not by the
 * layer above it, so a stub that simply returns its response object makes every
 * status a success — a 403 would resolve and this whole file would be testing an
 * error path that never happened. Rejecting here also produces the shape the
 * code under test reads: an Error carrying `response.status`, which is exactly
 * what separates an answer from a transport failure. fetchBoard's own
 * `validateStatus` (which admits 304) is honoured because it comes in on the
 * request config.
 *
 * @param {object} response - the stubbed axios response
 * @return {Promise<object>} the response, or a rejection carrying it
 */
function settle(response) {
	const accepted = response.config.validateStatus
		? response.config.validateStatus(response.status)
		: response.status >= 200 && response.status < 300
	if (accepted) {
		return response
	}
	const error = new Error(`Request failed with status code ${response.status}`)
	error.isAxiosError = true
	error.config = response.config
	error.response = response
	throw error
}

/**
 * Let every already-resolved promise chain — and Vue's scheduler — run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 30; i++) {
		await new Promise((resolve) => setImmediate(resolve))
	}
}

/**
 * A real useBoard for `boardId` with its own QueryClient, a cache already
 * holding the board (the situation the bug needs: a rendered board, fetched
 * while access was still held) and an adapter whose failure mode is settable.
 *
 * `fail(mode)` makes every subsequent request fail that way, board reads and
 * `/changes` alike — which is what a revocation really looks like, and what
 * makes the delta poll's own error path (drop cursor → invalidate → refetch)
 * the thing that delivers the answer, one poll after the change. `{ deltaToo:
 * false }` fails only the board read, which is the other order the same
 * revocation can arrive in (the 60s safety net beating a 30s push-cadence delta
 * tick to it) and the one where the poll's own cursor drop does NOT cover it.
 *
 * `boardId` may be a ref, so a test can switch the board the way BoardView does
 * — the component is reused across board switches, so this is a key change on a
 * live composable, not a remount.
 *
 * Must be called with mock timers already enabled.
 *
 * @param {import('node:test').TestContext} t
 * @param {number|import('vue').Ref} boardId - distinct per test; the cursor registry is module-scoped
 * @return {object} the composable's result plus counters and controls
 */
function harness(t, boardId) {
	const app = createApp({})
	// main.js's global staleTime. `retry: false` so a failure is one request and
	// lands in the same tick — the app retries once, which changes when the answer
	// arrives, never whether.
	const queryClient = new QueryClient({
		defaultOptions: { queries: { staleTime: 30_000, retry: false } },
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	// Every board this harness can serve looks the same bar its id, so a test that
	// switches boards gets a distinguishable payload for each without a fixture.
	const payload = (id) => ({
		board: { id: Number(id), title: `Board ${id}` },
		cards: [{ id: 1, stackId: 10, sortKey: 'a', title: `board-${id}-card` }],
		stacks: [{ id: 10 }],
		cursor: 1,
		etag: '1-31-internal',
	})
	const startId = boardQueryKey(boardId)[1]
	queryClient.setQueryData(boardQueryKey(boardId), payload(startId))
	seedCursor(startId, 1)

	const state = { mode: null, deltaToo: true }
	let boardReads = 0
	let deltaReads = 0
	axios.defaults.adapter = async (config) => {
		const isDelta = config.url.includes('/changes')
		if (isDelta) {
			deltaReads++
		} else {
			boardReads++
		}
		const id = config.url.match(/\/boards\/(\d+)/)?.[1]
		const failing = state.mode !== null && (state.deltaToo || !isDelta)
		if (failing && state.mode === 'network') {
			// What an aborted request / dropped connection produces: a rejection
			// with no `response` at all.
			throw new Error('Network Error')
		}
		const status = failing ? state.mode : 200
		return settle({
			status,
			statusText: status === 200 ? 'OK' : 'Failed',
			data: status !== 200
				? {}
				: (isDelta
					? { cursor: 1, resync: false, cards: { upsert: [], remove: [] }, stacks: { upsert: [], remove: [] } }
					: payload(id)),
			headers: {},
			config,
		})
	}

	const scope = effectScope()
	t.after(() => scope.stop())
	const board = app.runWithContext(() => scope.run(() => useBoard(boardId)))

	return {
		board,
		queryClient,
		key: boardQueryKey(boardId),
		boardReads: () => boardReads,
		deltaReads: () => deltaReads,
		/** Fail every subsequent request: a status code, or 'network'. */
		fail: (mode, { deltaToo = true } = {}) => {
			state.mode = mode
			state.deltaToo = deltaToo
		},
		/** Stop failing — the server (or the viewer's access) is back. */
		heal: () => { state.mode = null },
	}
}

test('access revoked entirely: the board stops rendering and its payload is dropped', async (t) => {
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const h = harness(t, 401)
	await flush()

	// The precondition, and the reason this bug was invisible: a rendered board.
	assert.equal(h.board.data.value?.cards.length, 1, 'the board starts out rendered')
	assert.equal(h.board.isError.value, false)

	// Access is revoked. The next delta poll 403s, which drops the cursor and
	// invalidates the board query, and the refetch is answered 403 too.
	h.fail(403)
	tick(DELTA_CADENCE)
	await flush()

	assert.ok(h.boardReads() > 0,
		'the revocation must actually have reached a board read — without one this '
		+ 'test would be asserting nothing')
	assert.equal(h.board.error.value?.response?.status, 403,
		'the error must be the access answer itself, so BoardView can pick the '
		+ '"you no longer have access" copy over the generic retry box')
	assert.equal(h.board.data.value, undefined,
		'and the payload must not still be handed to the view — a failed refetch '
		+ 'keeps `data`, and the stacks render from `data`, so the revoked board '
		+ 'went on showing its cards underneath the error box')

	// The whole returned state has to agree, not just the fields read today.
	// The cache entry is gone, so the observer rebuilds an empty `pending` query
	// behind it: left raw, this object would report an error that is also pending
	// and not-an-error, and the next consumer to branch on `isPending` would
	// render a skeleton that never resolves.
	assert.equal(h.board.isError.value, true, 'isError')
	assert.equal(h.board.status.value, 'error', 'status')
	assert.equal(h.board.isPending.value, false, 'isPending')
	assert.equal(h.board.isSuccess.value, false, 'isSuccess')
	assert.equal(h.board.isLoading.value, false, 'isLoading')

	assert.equal(h.queryClient.getQueryData(h.key), undefined,
		'the cached payload must be DROPPED, not merely hidden: it is dehydrated '
		+ 'into the offline snapshot on every cache change, so anything left here '
		+ 'is resurrected on the next navigation back to the board')

	// And it stays that way rather than looping. The window has to cross the 60s
	// `refetchInterval` — the safety-net re-read is what would otherwise fire a
	// fresh 403 a minute for as long as the tab is open, and a window of a few
	// delta cadences would never reach it, so the `enabled` guard would be pinned
	// by nothing.
	const settled = { board: h.boardReads(), delta: h.deltaReads() }
	for (let n = 0; n < 40; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}
	assert.equal(h.boardReads(), settled.board,
		'a revoked board must not re-read itself — three crossings of the 60s '
		+ 'safety-net interval must produce no request at all')
	assert.equal(h.deltaReads(), settled.delta,
		'and its delta poll must stop too — /changes can only 403 now, every 5s, '
		+ 'for as long as the tab is open')
	assert.equal(h.board.isError.value, true, 'the answer is sticky')
	assert.equal(h.board.data.value, undefined, 'and the board does not come back')
})

test('the poll stops even when the BOARD read is the one that got the answer', async (t) => {
	// The order the poll's own error path does not cover. syncBoardDelta drops the
	// cursor when /changes fails, and a cursorless poll no-ops — so when the delta
	// tick notices first, the loop goes quiet for free. When the 60s safety-net
	// re-read notices first (with push proven the delta cadence is 30s, so this is
	// an ordinary race, not a contrivance), the cursor is still seeded and the
	// poll would go on asking /changes for a board the viewer may not read.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const h = harness(t, 405)
	await flush()

	// Only the board read is refused; /changes keeps answering 200, so nothing
	// drops the cursor.
	h.fail(403, { deltaToo: false })
	for (let n = 0; n < 13; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}

	assert.equal(h.board.isError.value, true, 'the safety-net re-read delivered the answer')
	assert.ok(h.deltaReads() > 0, 'and the poll had been running up to that point')

	const settled = h.deltaReads()
	for (let n = 0; n < 6; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}
	assert.equal(h.deltaReads(), settled,
		'the delta poll must stop on the latch itself, not rely on having been the '
		+ 'one that failed — otherwise a board answered away by its safety-net '
		+ 'read keeps polling /changes every 5s until the tab closes')
})

test('switching to another board clears the answer with the board it belonged to', async (t) => {
	// BoardView is reused across board switches (router-view has no :key), so this
	// is a key change on a LIVE composable, not a remount. A latch that outlived
	// the board it came from would greet the next board the user opens with a
	// blank screen and a false "you no longer have access" — a worse bug than the
	// one being fixed, on a board nothing is wrong with.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const id = ref(406)
	const h = harness(t, id)
	await flush()

	h.fail(403)
	tick(DELTA_CADENCE)
	await flush()
	assert.equal(h.board.isError.value, true, 'board 406 is revoked')
	assert.equal(h.board.data.value, undefined)

	// The user picks another board from the sidebar. Their access to THAT one is
	// perfectly good.
	h.heal()
	id.value = 407
	await flush()
	tick(1)
	await flush()

	assert.equal(h.board.isError.value, false,
		'the next board must not inherit the previous board\'s 403')
	assert.equal(h.board.data.value?.cards[0].title, 'board-407-card',
		'and it must actually load and render')
	assert.equal(h.board.status.value, 'success')
})

test('a 500 leaves the cached board on screen', async (t) => {
	// The constraint that makes the fix above safe to ship. A server error is not
	// an answer about access: the viewer's rights are unknown, the payload they
	// hold was legitimately fetched, and blanking the board would replace a
	// working screen with an empty one every time a backend hiccups.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const h = harness(t, 402)
	await flush()

	h.fail(500)
	for (let n = 0; n < 4; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}

	assert.ok(h.boardReads() > 0, 'the 500 must have reached a board read')
	assert.equal(h.board.error.value?.response?.status, 500,
		'the failure under test must be the 500, not something else')
	assert.equal(h.board.data.value?.cards.length, 1,
		'a 500 must leave the rendered board exactly where it was — the retryable '
		+ 'error box appears OVER it, which is the existing behaviour and the right '
		+ 'one: the viewer\'s access is not in question, the server just stumbled')
	assert.equal(h.board.data.value?.cards[0].title, 'board-402-card')
	assert.ok(h.queryClient.getQueryData(h.key) !== undefined,
		'and the cached payload must survive a transient failure — the offline '
		+ 'cache and the 304 path both rest on a failed re-read changing nothing')
})

test('a dropped connection leaves the cached board on screen', async (t) => {
	// The other transient shape, and the one that reads differently in code: a
	// transport failure has no `response`, so any check that reaches for a status
	// finds `undefined`. That must land on the transient side, not the terminal
	// one — an offline blip is precisely when the cached board matters most.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const h = harness(t, 403)
	await flush()

	h.fail('network')
	for (let n = 0; n < 4; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}

	assert.ok(h.boardReads() > 0, 'the dropped connection must have reached a board read')
	assert.equal(h.board.error.value?.response, undefined,
		'a transport failure carries no response — the case a status check must not '
		+ 'mistake for an answer')
	assert.equal(h.board.data.value?.cards.length, 1,
		'an offline blip must leave the rendered board on screen')
	assert.ok(h.queryClient.getQueryData(h.key) !== undefined,
		'and must not drop the payload the offline cache exists to keep')
})

test('a 404 is terminal too, and a healthy board is untouched by any of this', async (t) => {
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const h = harness(t, 404)
	await flush()

	// The control: nothing fails, so nothing changes. Without it, a mask that
	// simply always reported "revoked" would pass every assertion above.
	for (let n = 0; n < 4; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}
	assert.equal(h.board.isError.value, false, 'a healthy board is never terminal')
	assert.equal(h.board.data.value?.cards.length, 1, 'and goes on rendering')

	// A deleted board answers 404 rather than 403, and is just as terminal: the
	// payload describes something that no longer exists.
	h.fail(404)
	tick(DELTA_CADENCE)
	await flush()

	assert.equal(h.board.isError.value, true, 'a deleted board is terminal as well')
	assert.equal(h.board.error.value?.response?.status, 404)
	assert.equal(h.board.data.value, undefined)
	assert.equal(h.queryClient.getQueryData(h.key), undefined)
})

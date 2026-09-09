// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10225 — a dead notify_push daemon must not slow every board down.
//
// `listen()` reports only that the server ADVERTISES notify_push: it returns
// `window._notify_push_available`, which the library sets from the capability
// object before it has even constructed the WebSocket. So on an instance where
// the daemon is stopped or the proxy never forwards /push, the advertisement
// still says "push is available" — and the client used to trust it, stretching
// its delta poll from 5s to 30s while no frame could ever arrive. Every change
// then took up to 30s to appear, which is WORSE than the push-less fallback.
//
// The fix inverts the default: push is not live until a frame proves it. That is
// two coupled pieces, and neither works alone —
//   1. realtime.js latches `confirmed` on the first received frame, and
//      pushActive() is `available && confirmed`.
//   2. useBoard re-reads pushActive() on every poll tick. setInterval captured
//      its delay once at setup, so the flag flipping later changed nothing.
// This file pins the OBSERVABLE cadence, so it fails if either half regresses.
//
// Asserted here rather than in Playwright deliberately, for the same reason
// spelled out in queryKeys.test.mjs: the trigger is a notify_push frame, and
// push is unavailable in the dev stack and explicitly disabled in CI
// (KANSO_SKIP_NOTIFY_PUSH=1) — a browser test would pass vacuously, with
// pushActive() false for the wrong reason (nothing advertised at all).
//
// Rig follows cardMoveQueue.test.mjs: a `window` stub before any @nextcloud
// import, dynamic imports in that order, the real composable under
// app.runWithContext, and the transport stubbed at the axios ADAPTER, so the
// real timer loop, the real services/api.js call and the real syncBoardDelta run.

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

const EVENT_NAME = 'kanso_board_changed'

// The notify_push globals are pre-seeded so the REAL `listen()` from the
// dependency takes its "socket already up" branch: it registers our handler in
// `_notify_push_listeners`, sends `listen <name>` down the stub socket and
// returns `_notify_push_available`. No capabilities lookup, no pre_auth POST, no
// WebSocket — but the registration path, and therefore the handler we later
// invoke, is the library's own.
globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
	_notify_push_listeners: {},
	_notify_push_ws: { send() {} },
	_notify_push_ready: true,
	// The server advertises push. Whether it WORKS is what this file is about.
	_notify_push_available: true,
	_notify_push_online: true,
	_notify_push_error_count: 0,
}

const { createApp, effectScope } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { initRealtime, pushActive } = await import('../../src/services/realtime.js')
const { useBoard } = await import('../../src/composables/useBoard.js')
const { useCardMove } = await import('../../src/composables/useCardMove.js')
const { seedCursor } = await import('../../src/composables/useBoardDelta.js')
const { boardQueryKey } = await import('../../src/composables/queryKeys.js')

const FALLBACK_CADENCE = 5_000
const PROVEN_CADENCE = 30_000

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
 * Let every already-resolved promise chain run. `tick()` only fires
 * timers; the delta request they start settles in microtasks/immediates.
 * setImmediate is deliberately NOT among the mocked apis so this still works.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 20; i++) {
		await new Promise((resolve) => setImmediate(resolve))
	}
}

/**
 * A real useBoard for `boardId`, with its own QueryClient, a seeded delta cursor
 * (exactly what the query's own queryFn does after a full board read) and an
 * axios adapter that counts `/changes` reads.
 *
 * Must be called with mock timers already enabled: the poll loop arms its first
 * timeout during setup.
 *
 * Also returns the board's real move queue, so a test can put the board into the
 * mid-drag state the poll has to skip (and survive).
 *
 * @param {number} boardId - distinct per test; the cursor registry is module-scoped
 * @return {{deltaReads: () => number, enqueueMove: Function, releaseMove: () => void}}
 */
function harness(boardId) {
	const app = createApp({})
	const queryClient = new QueryClient()
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	queryClient.setQueryData(boardQueryKey(boardId), {
		cards: [{ id: 1, stackId: 10, sortKey: 'a' }],
		stacks: [],
	})
	seedCursor(boardId, 1)

	let changes = 0
	// A move request parks here until releaseMove() is called, which is what keeps
	// isBoardMovePending(boardId) true for as many poll ticks as a test needs.
	let unpark = null
	axios.defaults.adapter = async (config) => {
		const isDelta = config.url.includes('/changes')
		if (isDelta) changes++
		if (config.url.includes('/move')) {
			await new Promise((resolve) => { unpark = resolve })
		}
		return {
			status: 200,
			statusText: 'OK',
			data: isDelta
				? { cursor: 1, cards: { upsert: [], remove: [] }, stacks: { upsert: [], remove: [] } }
				: { id: boardId, cards: [], stacks: [], cursor: 1, stackId: 20, sortKey: 'm', lastModified: 1 },
			headers: {},
			config,
		}
	}

	const scope = effectScope()
	scopes.push(scope)
	const board = app.runWithContext(() => scope.run(() => {
		// Same app context, so the move queue shares this board's QueryClient.
		const move = useCardMove(boardId)
		useBoard(boardId)
		return move
	}))

	return {
		deltaReads: () => changes,
		enqueueMove: board.enqueueMove,
		releaseMove: () => unpark?.(),
	}
}

/** Deliver a push frame exactly the way the library's onmessage handler does. */
function pushFrame(boardId) {
	for (const cb of window._notify_push_listeners[EVENT_NAME]) {
		cb(EVENT_NAME, { boardId })
	}
}

test('an advertised-but-unproven push channel polls at the 5s fallback, then stretches to 30s once a frame lands', async (t) => {
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	// t.mock.timers is this test's own tracker — the module-level `mock` export is
	// a different one and would refuse to tick.
	const tick = (ms) => t.mock.timers.tick(ms)

	// The advertisement is true — this is the dead-daemon instance, indistinguishable
	// from a healthy one at this point — and it must NOT count as live.
	assert.equal(initRealtime(() => {}), true,
		'the rig must reproduce a server that advertises notify_push')
	assert.equal(pushActive(), false,
		'the capability alone is not evidence the daemon or the proxy work; '
		+ 'pushActive() must not be satisfied by the advertisement')

	const { deltaReads } = harness(101)

	// While no frame has arrived we are on the fast fallback, tick by tick.
	tick(FALLBACK_CADENCE)
	await flush()
	assert.equal(deltaReads(), 1,
		'with push unproven the delta poll must run at the 5s fallback — a dead '
		+ 'daemon on the 30s cadence means changes take up to 30s to appear')

	tick(FALLBACK_CADENCE)
	await flush()
	assert.equal(deltaReads(), 2, 'the 5s cadence must repeat, not fire once')

	// A frame arrives: the whole path (capability, daemon, proxy, auth) is proven.
	pushFrame(101)
	assert.equal(pushActive(), true, 'a received frame is what proves push is live')

	// The timeout already armed keeps its 5s delay; the stretch takes effect when
	// the loop re-arms, which is the tick after the frame.
	tick(FALLBACK_CADENCE)
	await flush()
	assert.equal(deltaReads(), 3)

	// From here the cadence is 30s — and nothing before 30s.
	tick(PROVEN_CADENCE - 1)
	await flush()
	assert.equal(deltaReads(), 3,
		'once push is proven the delta poll must back off to 30s; still polling at '
		+ '5s means useBoard is not re-reading pushActive() per tick')

	tick(1)
	await flush()
	assert.equal(deltaReads(), 4, 'the 30s safety-net poll must still fire')
})

test('the confirmation latch is one-way and board-independent', async (t) => {
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	// t.mock.timers is this test's own tracker — the module-level `mock` export is
	// a different one and would refuse to tick.
	const tick = (ms) => t.mock.timers.tick(ms)

	// Deliberately self-sufficient rather than inheriting test 1's latch: running
	// one test alone (--test-name-pattern while debugging, a shard, a .only) must
	// fail only for real reasons, and `confirmed` is module-global process state.
	// The cross-board claim is carried by harness(102) below — a board opened for
	// the FIRST time here, on a socket whose only frame named a different board —
	// not by the process state.
	initRealtime(() => {})
	pushFrame(999)
	assert.equal(pushActive(), true, 'the latch must survive across boards')

	const { deltaReads } = harness(102)

	tick(PROVEN_CADENCE - 1)
	await flush()
	assert.equal(deltaReads(), 0,
		'a board opened after push was proven must arm at 30s from the start')

	tick(1)
	await flush()
	assert.equal(deltaReads(), 1)

	// No further frames arrive — a healthy socket on an idle board sends nothing
	// Kanso can observe, so silence must never be read as "push died". Without
	// this, a future liveness timer would make the cadence flap between 5s and
	// 30s and no test would notice. (One window per tick: MockTimers does not run
	// a timer the callback of an already-fired timer scheduled during the same
	// tick, and the loop re-arms itself from inside its own callback.)
	for (let window = 0; window < 3; window++) {
		tick(PROVEN_CADENCE - 1)
		await flush()
		assert.equal(deltaReads(), window + 1,
			'silence is not evidence of death: the cadence must stay at 30s and never '
			+ 'fall back to 5s')
		tick(1)
		await flush()
		assert.equal(deltaReads(), window + 2)
	}
	assert.equal(pushActive(), true, 'the latch must not clear itself')
})

test('the poll survives ticks it skips: a mid-drag board still polls afterwards', async (t) => {
	// The cost of turning setInterval into a self-rescheduling timeout: the loop
	// now owns its own continuation. setInterval fired again no matter what its
	// callback did; this one stops FOREVER — leaving only the 60s refetch — if any
	// tick fails to re-arm. The mid-drag skip is the one branch that deliberately
	// does no work, so it is where that mistake is easiest to make.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	initRealtime(() => {})
	pushFrame(999)
	const cadence = PROVEN_CADENCE

	const { deltaReads, enqueueMove, releaseMove } = harness(103)

	// A drag whose server call is parked: the board stays move-pending across as
	// many ticks as we like, and syncBoardDelta must not patch over the optimistic
	// placement while it is.
	enqueueMove({ cardId: 1, targetStackId: 20, afterCardId: null, optimisticKey: 'z' })
	await flush()

	for (let skipped = 0; skipped < 2; skipped++) {
		tick(cadence)
		await flush()
		assert.equal(deltaReads(), 0,
			'the delta poll must not read while a move is pending — that patch would '
			+ 'clobber the optimistic placement mid-drag')
	}

	// The move lands, the queue drains, the board is no longer pending.
	releaseMove()
	await flush()

	tick(cadence)
	await flush()
	assert.equal(deltaReads(), 1,
		'the poll must resume after the drag: a skipped tick that forgets to '
		+ 're-arm kills realtime for this board for the rest of the session')

	tick(cadence)
	await flush()
	assert.equal(deltaReads(), 2, 'and keep going')
})

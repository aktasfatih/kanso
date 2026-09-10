// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10384 — the board's 60s safety-net re-read must actually happen.
//
// useBoard sets `refetchInterval: 60_000` and calls it, in as many words, the
// "belt-and-suspenders full refetch … that self-heals any missed delta". It did
// nothing at all. syncBoardDelta called `setQueryData` on EVERY delta tick,
// empty ones included; every cache write dispatches a `success` on the query;
// QueryObserver.onQueryUpdate then runs #updateTimers, and
// #updateRefetchInterval unconditionally clears the interval and creates a new
// one. The delta poll ticks every 5s (30s once push is proven), always sooner
// than the 60s the interval is counting to, so the interval was reset from zero
// forever. Measured in a real browser: ZERO GET /boards/{id} in 100 seconds with
// a board open. The same dispatch refreshes `dataUpdatedAt`, so
// `refetchOnMount` and `refetchOnWindowFocus` were dead for an open board too —
// all three of the query's freshness paths, gone.
//
// Why that is worth a test rather than a comment: delta sync is then the ONLY
// refresh path for an open board, and delta sync can only see state that writes
// a `kanso_changes` row. Some does not. The sharpest case cannot be fixed by
// writing one either — a Nextcloud GROUP membership changes outside Kanso
// entirely, so no Kanso write path exists to append a row from — which makes
// this periodic re-read the single mechanism that can heal it. A regression here
// is invisible: nothing errors, nothing looks wrong, a board just quietly stops
// re-reading.
//
// The re-read stays cheap: it goes through fetchBoard's conditional path, so an
// unchanged board answers 304 (~1.5 KB) rather than re-downloading itself. That
// half is pinned by boardEtag.test.mjs and tests/e2e/board-etag.spec.js; this
// file only pins that the read HAPPENS.
//
// Rig is boardPollVisibility.test.mjs's: a `window` stub before any @nextcloud
// import, dynamic imports in that order, the real composable under
// app.runWithContext, transport stubbed at the axios ADAPTER — so the real timer
// loop, the real TanStack observer and the real syncBoardDelta all run.

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
const { useBoard } = await import('../../src/composables/useBoard.js')
const { seedCursor } = await import('../../src/composables/useBoardDelta.js')
const { boardQueryKey } = await import('../../src/composables/queryKeys.js')

// Defined AFTER the imports, and deliberately not a DOM — same reasoning as
// boardPollVisibility.test.mjs. TanStack's focusManager reads
// `document.visibilityState` at interval-fire time to decide whether to run the
// refetch (refetchIntervalInBackground is false by default), so it has to be
// present and 'visible' or the interval fires and does nothing.
globalThis.document = {
	hidden: false,
	visibilityState: 'visible',
	addEventListener() {},
	removeEventListener() {},
}

// useBoard's own two cadences. No initRealtime() and no push frame, so
// pushActive() is false and the delta loop is on the 5s fallback — the worst
// case for this bug, since it re-armed the 60s interval twelve times over.
const DELTA_CADENCE = 5_000
const REFETCH_INTERVAL = 60_000

const clients = []
after(() => {
	for (const client of clients) {
		client.unmount()
		client.clear()
	}
})

/**
 * Let every already-resolved promise chain run. `tick()` only fires timers; the
 * requests they start settle in microtasks/immediates.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 30; i++) {
		await new Promise((resolve) => setImmediate(resolve))
	}
}

/**
 * A real useBoard for `boardId` with its own QueryClient, a seeded delta cursor
 * and an adapter that counts full board reads and delta reads separately.
 *
 * The delta responses are controlled by `nextDelta`: empty by default (what
 * almost every real tick is), and settable to a non-empty window for the test
 * that checks a real change still writes the cache.
 *
 * Must be called with mock timers already enabled — both the poll loop and the
 * query observer arm their timers during setup.
 *
 * @param {import('node:test').TestContext} t
 * @param {number} boardId - distinct per test; the cursor registry is module-scoped
 * @return {object} counters and controls
 */
function harness(t, boardId) {
	const app = createApp({})
	// main.js's global staleTime, so the freshness behaviour here is the app's.
	const queryClient = new QueryClient({
		defaultOptions: { queries: { staleTime: 30_000, retry: false } },
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	const state = { cursor: 1, delta: null }
	queryClient.setQueryData(boardQueryKey(boardId), {
		board: { id: boardId, title: 'Board' },
		cards: [{ id: 1, stackId: 10, sortKey: 'a' }],
		stacks: [{ id: 10 }],
		cursor: state.cursor,
		etag: `${state.cursor}-15-internal`,
	})
	seedCursor(boardId, state.cursor)

	let boardReads = 0
	let deltaReads = 0
	axios.defaults.adapter = async (config) => {
		if (config.url.includes('/changes')) {
			deltaReads++
			const delta = state.delta ?? { cards: { upsert: [], remove: [] }, stacks: { upsert: [], remove: [] } }
			state.delta = null
			return {
				status: 200,
				statusText: 'OK',
				data: { cursor: state.cursor, resync: false, ...delta },
				headers: {},
				config,
			}
		}
		boardReads++
		return {
			status: 200,
			statusText: 'OK',
			data: {
				board: { id: boardId, title: 'Board' },
				cards: [{ id: 1, stackId: 10, sortKey: 'a' }],
				stacks: [{ id: 10 }],
				cursor: state.cursor,
				etag: `${state.cursor}-15-internal`,
			},
			headers: {},
			config,
		}
	}

	const scope = effectScope()
	t.after(() => scope.stop())
	app.runWithContext(() => scope.run(() => useBoard(boardId)))

	return {
		boardReads: () => boardReads,
		deltaReads: () => deltaReads,
		/** Make the NEXT delta tick carry a real change. */
		queueChange: (card) => {
			state.cursor++
			state.delta = { cards: { upsert: [card], remove: [] }, stacks: { upsert: [], remove: [] } }
		},
	}
}

test('an open board still re-reads itself once a minute while the delta poll runs', async (t) => {
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const h = harness(t, 301)
	await flush()

	// No mount read: the harness seeds the cache the way a board switch back to
	// an already-loaded board does, and main.js's 30s staleTime makes that entry
	// fresh, so `refetchOnMount` correctly declines. Everything below is counted
	// from there, which is also the exact situation the bug was worst in — the
	// user is sitting on a board they already have.
	const afterMount = h.boardReads()
	assert.equal(afterMount, 0, 'a freshly-seeded board does not re-read at mount')

	// Eleven delta cadences — 55s, one short of the interval. Empty windows, the
	// overwhelming majority of real ticks.
	for (let n = 0; n < 11; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}
	// The anchor. Without it "no extra read yet" below would also pass if the
	// poll loop were dead, and this file would be testing nothing.
	assert.equal(h.deltaReads(), 11, 'the delta poll must be running at its 5s cadence')
	assert.equal(h.boardReads(), afterMount,
		'the safety-net re-read is a 60s one — it must not fire early either')

	// …and the twelfth takes us to 60s, where the interval is due.
	tick(DELTA_CADENCE)
	await flush()
	assert.equal(h.boardReads(), afterMount + 1,
		'the board must re-read itself after 60s. Writing the query cache on an '
		+ 'EMPTY delta tick re-arms this interval from zero every 5s, so it never '
		+ 'fires and the board never re-reads at all — which strands every field '
		+ 'delta sync cannot see, group membership above all, permanently stale')

	// It keeps going, rather than firing once and dying.
	for (let n = 0; n < 12; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}
	assert.equal(h.boardReads(), afterMount + 2,
		'and it must keep re-reading on every subsequent minute')
})

test('a delta that carries real changes still patches the cache', async (t) => {
	// The other direction, and the reason the empty-tick skip is a skip and not a
	// removal: a NON-empty window must still write the query cache. Deleting the
	// setQueryData call altogether would make the test above pass and break
	// realtime completely, so this is what separates the two.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const h = harness(t, 302)
	await flush()

	const key = boardQueryKey(302)
	const client = clients.at(-1)
	assert.equal(client.getQueryData(key).cards.length, 1)

	h.queueChange({ id: 2, stackId: 10, sortKey: 'b', title: 'arrived by delta' })
	tick(DELTA_CADENCE)
	await flush()

	const cards = client.getQueryData(key).cards
	assert.equal(cards.length, 2, 'a non-empty delta must still reach the cache')
	assert.equal(cards.find((c) => c.id === 2)?.title, 'arrived by delta')
})

test('a real delta re-arms the interval, so the safety net is 60s of QUIET', async (t) => {
	// The interval is not a wall-clock schedule; it is "60s since this query last
	// updated", because TanStack re-arms it on every cache write. That is the
	// intended shape — the re-read exists to catch what delta sync missed, and a
	// board that just received a delta has nothing to catch up on — but it is
	// worth stating, so a later reader does not file the 61st-second read below
	// as a bug.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const h = harness(t, 303)
	await flush()
	const afterMount = h.boardReads()

	// A real change lands at t=5s, restarting the 60s clock from there.
	h.queueChange({ id: 3, stackId: 10, sortKey: 'c' })
	tick(DELTA_CADENCE)
	await flush()

	// t=60s from mount: the ORIGINAL deadline, which the delta above pushed back.
	for (let n = 0; n < 11; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}
	assert.equal(h.boardReads(), afterMount,
		'a board that just took a delta has nothing to heal, so the interval is '
		+ 'measured from the last update, not from mount')

	// t=65s from mount = 60s after the delta.
	tick(DELTA_CADENCE)
	await flush()
	assert.equal(h.boardReads(), afterMount + 1,
		'and the safety net still fires 60s after the last thing that happened')
})

test('the periodic re-read is conditional, so an unchanged board stays cheap', async (t) => {
	// The constraint that makes this fix safe to ship: the restored re-read must
	// go through fetchBoard's conditional path (#10299), not become a full
	// re-download once a minute. Measured there at -98.8% on a 180-card board and
	// -99.9% on a 2 000-card board; a re-read that forgot the validator would
	// hand all of that back.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const validators = []
	const h = harness(t, 304)
	// Wrap the harness adapter so the validator on each board read is recorded.
	const inner = axios.defaults.adapter
	axios.defaults.adapter = async (config) => {
		if (!config.url.includes('/changes')) {
			const headers = config.headers
			validators.push(
				(typeof headers?.get === 'function' ? headers.get('If-None-Match') : headers?.['If-None-Match']) ?? null
			)
		}
		return inner(config)
	}
	await flush()

	for (let n = 0; n < 12; n++) {
		tick(DELTA_CADENCE)
		await flush()
	}
	assert.equal(h.boardReads(), 1, 'exactly the one 60s re-read')
	assert.equal(validators.at(-1), '"1-15-internal"',
		'the periodic re-read must replay the validator of the payload it already '
		+ 'holds — otherwise the safety net costs a full board assembly every '
		+ 'minute per open tab, which is a worse trade than the staleness it fixes')
})

// Kept as a named constant so the numbers above stay readable; asserted here so
// a change to either cadence has to come past this file.
test('the cadences this file assumes are the ones useBoard uses', () => {
	assert.equal(DELTA_CADENCE, 5_000)
	assert.equal(REFETCH_INTERVAL, 60_000)
})

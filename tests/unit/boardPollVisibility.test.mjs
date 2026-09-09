// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10278 — a board parked in a background tab must stop polling /changes.
//
// Every OTHER feed in the app already does: the My Work queries are TanStack
// `refetchInterval` queries, and TanStack's refetchIntervalInBackground default
// of false skips interval ticks while the tab is hidden (documented at
// queryKeys.js MY_WORK_POLL_INTERVAL, useMyReviews.js, useInbox.js). The board
// delta poll is the one hand-rolled loop - it has to be a timeout, not
// `refetchInterval`, because #10225 requires the cadence to be re-read on every
// tick - so it inherits none of that and used to hit /changes forever in a tab
// nobody was looking at. That tick is not free either: GET
// /api/boards/{id}/changes has no ETag/304 path (BoardController::changes says
// so in as many words), so an empty delta still costs a request, an ACL lookup
// and a findSince.
//
// The trap this file exists to nail down is the SHAPE of the skip, not the skip.
// The loop owns its own continuation, so implementing "hidden ⇒ don't fetch" as
// an early `return` past the re-arm would kill the poll permanently - and for
// every VISIBLE tab too, since the chain never restarts. pushLiveness.test.mjs
// already pins that for the mid-drag skip; this pins it for the hidden skip, and
// the hidden→visible resume assertion below is the one that fails if the skip
// ever regresses into a `return`.
//
// The file also owns the loop's TEARDOWN, because both halves of useBoard's
// `onScopeDispose` are the same claim as the skip above - "a board nobody is
// looking at makes no requests" - just for a board that is gone rather than
// hidden. The listener half and the timer half get one test each.
//
// Rig is pushLiveness.test.mjs's: a `window` stub before any @nextcloud import,
// dynamic imports in that order, the real composable under app.runWithContext,
// transport stubbed at the axios ADAPTER, so the real timer loop, the real
// services/api.js call and the real syncBoardDelta run.

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

// Deliberately defined AFTER the imports above, and deliberately not a DOM: a
// `document` global present while `vue` initialises makes it pick up this object
// as its document (see the note in cardMoveQueue.test.mjs). useBoard reads
// `document` lazily, per tick and per listener call, so a late stub is enough -
// and everything else stays on the no-DOM path it already takes today.
const visibilityListeners = new Set()
globalThis.document = {
	hidden: false,
	visibilityState: 'visible',
	addEventListener(type, cb) {
		if (type === 'visibilitychange') visibilityListeners.add(cb)
	},
	removeEventListener(type, cb) {
		if (type === 'visibilitychange') visibilityListeners.delete(cb)
	},
}

/**
 * Set the visibility state WITHOUT dispatching the event.
 *
 * Both properties, because a real browser sets both and they are the same bit:
 * the app reads `visibilityState` (useBoard, main.js, TanStack's focusManager),
 * but a rig that stubs only the property today's code happens to read pins the
 * property name instead of the behaviour, and turns a harmless swap between the
 * two into a red suite with a misleading message.
 *
 * @param {boolean} hidden
 */
function setVisibility(hidden) {
	document.hidden = hidden
	document.visibilityState = hidden ? 'hidden' : 'visible'
}

/** Hide or reveal the tab exactly as the browser does: state first, then event. */
function setHidden(hidden) {
	setVisibility(hidden)
	for (const cb of [...visibilityListeners]) {
		cb()
	}
}

// No initRealtime() and no push frame, so pushActive() is false and the loop is
// on the 5s push-less fallback - the cadence a background tab is most expensive
// on, and the one this card is about.
const CADENCE = 5_000

// Scopes are stopped per test (see harness); only the query clients need a
// process-wide teardown, so their gcTime timers don't hold the run open.
const clients = []
after(() => {
	for (const client of clients) {
		client.unmount()
		client.clear()
	}
})

/**
 * Let every already-resolved promise chain run. `tick()` only fires timers; the
 * delta request they start settles in microtasks/immediates. setImmediate is
 * deliberately NOT among the mocked apis so this still works.
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
 * (what the query's own queryFn does after a full board read) and an axios
 * adapter that counts `/changes` reads.
 *
 * Must be called with mock timers already enabled: the poll loop arms its first
 * timeout during setup.
 *
 * Torn down at the end of the test that created it, and that is not tidiness:
 * a board left mounted keeps its visibilitychange listener registered, so the
 * NEXT test's setHidden() would sync that board too - through this test's axios
 * adapter, inflating this test's count. (Which is how the shared-listener nature
 * of the real thing showed up here first.)
 *
 * @param {import('node:test').TestContext} t
 * @param {number} boardId - distinct per test; the cursor registry is module-scoped
 * @return {{deltaReads: () => number, scope: import('vue').EffectScope}}
 */
function harness(t, boardId) {
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
	axios.defaults.adapter = async (config) => {
		const isDelta = config.url.includes('/changes')
		if (isDelta) changes++
		return {
			status: 200,
			statusText: 'OK',
			data: isDelta
				? { cursor: 1, cards: { upsert: [], remove: [] }, stacks: { upsert: [], remove: [] } }
				: { id: boardId, cards: [], stacks: [], cursor: 1, lastModified: 1 },
			headers: {},
			config,
		}
	}

	const scope = effectScope()
	t.after(() => scope.stop())
	app.runWithContext(() => scope.run(() => useBoard(boardId)))

	// The scope is handed back so a test can dispose the board MID-TEST. Timer
	// leaks are only observable that way: MockTimers is per-test and drops every
	// pending timer when it restores, so a timer leaked by a test that disposes in
	// its `after` hook can never fire anywhere. See the two teardown tests below.
	return { deltaReads: () => changes, scope }
}

test('a hidden tab makes no delta requests, and the poll resumes when the tab comes back', async (t) => {
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	// t.mock.timers is this test's own tracker — the module-level `mock` export is
	// a different one and would refuse to tick.
	const tick = (ms) => t.mock.timers.tick(ms)

	const { deltaReads } = harness(t, 201)

	// Anchor: the rig really does observe a poll when the tab is visible. Without
	// this the "no requests while hidden" assertion below could pass because
	// nothing polls at all.
	tick(CADENCE)
	await flush()
	assert.equal(deltaReads(), 1, 'a visible tab must poll at the 5s fallback')

	setHidden(true)

	// (a) Backgrounded: several full cadences must produce nothing. /changes has
	// no 304 path, so each of these ticks would be a full request + ACL +
	// findSince on the server, forever, for a tab nobody is looking at.
	for (let window = 0; window < 3; window++) {
		tick(CADENCE)
		await flush()
		assert.equal(deltaReads(), 1,
			'a hidden tab must not read /changes: every sibling feed pauses in the '
			+ 'background (refetchIntervalInBackground=false) and this loop must too')
	}

	// (b) THE important half. The loop owns its own continuation, so a skip
	// implemented as an early `return` past the re-arm would have killed the
	// chain during the three hidden windows above - permanently, for visible tabs
	// as well. Coming back must find the poll still alive.
	//
	// setVisibility, NOT setHidden: dispatching the event here would let the
	// visibilitychange handler produce the read, and the assertion below would
	// pass with a dead loop. This is the one place in the file that must reveal
	// the tab silently - do not "tidy" it into setHidden().
	setVisibility(false)
	tick(CADENCE)
	await flush()
	assert.equal(deltaReads(), 2,
		'the poll must survive the ticks it skips: a hidden tick that forgets to '
		+ 're-arm kills realtime for this board for the rest of the session')

	tick(CADENCE)
	await flush()
	assert.equal(deltaReads(), 3, 'and keep going at the normal cadence')
})

test('becoming visible syncs immediately instead of waiting out the interval', async (t) => {
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)

	const { deltaReads } = harness(t, 202)

	setHidden(true)
	tick(CADENCE)
	await flush()
	assert.equal(deltaReads(), 0, 'nothing polls while hidden')

	// (c) No timers are advanced across this transition on purpose: the fetch has
	// to come from the visibilitychange handler, not from the loop. Otherwise a
	// user returning to a parked board would stare at stale cards for a whole
	// interval - up to 30s once push is proven.
	setHidden(false)
	await flush()
	assert.equal(deltaReads(), 1,
		'the transition to visible must sync at once, without waiting for the next '
		+ 'poll tick')

	// And the loop is still the loop - the immediate sync does not double up or
	// replace it.
	tick(CADENCE)
	await flush()
	assert.equal(deltaReads(), 2)
})

test('the visibility listener is removed when the board scope is disposed', async (t) => {
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })

	const before = visibilityListeners.size
	const app = createApp({})
	const queryClient = new QueryClient()
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)
	const scope = effectScope()
	// Registered before the assertions, not after: a throw in between would
	// otherwise leak the listener into every test added after this one. (No cursor
	// is seeded for 203 and the adapter is whatever the last harness left, so
	// nothing here asserts on requests - only on the listener registry.)
	t.after(() => scope.stop())
	app.runWithContext(() => scope.run(() => useBoard(203)))
	assert.equal(visibilityListeners.size, before + 1,
		'useBoard must register exactly one visibilitychange listener')

	scope.stop()
	assert.equal(visibilityListeners.size, before,
		'and drop it on scope dispose. BoardView itself is reused across board '
		+ 'switches (see useBoard\'s own note on the board key), but CardDetail '
		+ 'and BoardSettingsModal each compose useBoard per open: without this, '
		+ 'opening and closing fifty cards leaves fifty listeners, every one of '
		+ 'them firing a /changes read on every single tab focus')
})

test('the delta poll timer is cleared when the board scope is disposed', async (t) => {
	// The other half of the same dispose callback, and the half with no coverage
	// until #10292: deleting `clearTimeout(deltaTimer)` left the whole
	// realtime suite green. Same lifecycle as the listener above, same
	// fifty-open-cards argument, and a worse failure mode - a leaked LISTENER
	// costs one read per tab focus, a leaked POLL costs one read every 5s forever,
	// on an endpoint with no 304 path.
	//
	// It has to be asserted INSIDE one test. MockTimers is per-test and discards
	// pending timers on restore, so a timer leaked by a board disposed in a
	// `t.after` hook is thrown away before the next test could ever observe it -
	// which is exactly why the existing tests could not catch this.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)
	setVisibility(false)

	const { deltaReads, scope } = harness(t, 204)

	// Anchor, same as everywhere else in this file: the loop really is running, so
	// the silence asserted after the dispose means "stopped", not "never started".
	tick(CADENCE)
	await flush()
	assert.equal(deltaReads(), 1, 'the poll must be live before the dispose is meaningful')

	scope.stop()

	// The chain re-arms from inside its own callback, so at this moment exactly one
	// timeout is outstanding - the one the tick above scheduled. If dispose does
	// not clear it, it fires here, does a full /changes read for a board nobody is
	// looking at, and re-arms itself again: an immortal loop, one per closed card
	// modal.
	for (let window = 0; window < 3; window++) {
		tick(CADENCE)
		await flush()
		assert.equal(deltaReads(), 1,
			'a disposed board must stop polling: CardDetail and BoardSettingsModal '
			+ 'compose useBoard per open, so a poll that outlives its scope means '
			+ 'fifty opened-and-closed cards leave fifty 5s /changes loops running '
			+ 'for the rest of the session')
	}
})

test('a dispose landing inside a tick does not let the re-arm outlive it', async (t) => {
	// The `stopped` latch (#10292). clearTimeout above only cancels a PENDING timer,
	// so it cannot help once a tick is already running: a dispose that lands after
	// the callback entered its `try` and before the `finally` leaves the finally free
	// to arm a fresh timer that NOTHING holds a handle to. That one is unkillable -
	// no clearTimeout can reach it and no later dispose knows about it - so it polls
	// /changes for a dead board until the page is closed.
	//
	// The interleaving is INJECTED, and deliberately so: no real path to it is known
	// (Vue defers unmount to its scheduler), which is exactly why the latch needs a
	// test to be worth having. The injection point is honest rather than arbitrary -
	// `isHidden()` reads document.visibilityState from inside the try, so a getter
	// that disposes on read reproduces "disposed mid-tick" at the precise moment the
	// hypothesis is about, deterministically and with no timing race.
	t.mock.timers.enable({ apis: ['setTimeout', 'setInterval'] })
	const tick = (ms) => t.mock.timers.tick(ms)
	setVisibility(false)

	const { deltaReads, scope } = harness(t, 205)

	let disposedInsideTick = false
	Object.defineProperty(document, 'visibilityState', {
		configurable: true,
		get() {
			if (!disposedInsideTick) {
				disposedInsideTick = true
				scope.stop()
			}
			return 'visible'
		},
	})
	tick(CADENCE)
	await flush()
	// Back to a plain property immediately: setVisibility() assigns to it, and this
	// module is ESM (strict), so a getter-only property left behind would throw in
	// any test added after this one.
	Object.defineProperty(document, 'visibilityState', {
		configurable: true, writable: true, enumerable: true, value: 'visible',
	})
	assert.ok(disposedInsideTick,
		'the rig must actually have disposed from inside the tick, or this test '
		+ 'proves nothing - if shouldSync() stops reading visibilityState, move the '
		+ 'injection to whatever it does read inside the try')

	// The anchor, and it is doing more work than the usual one. The getter above is
	// first-read-wins, so `disposedInsideTick` only proves SOMETHING read
	// visibilityState - if a future reader (TanStack's focusManager reads the same
	// property) got there first, the dispose would have landed BEFORE the tick, which
	// plain clearTimeout already handles, and this test would quietly stop exercising
	// the latch at all. This read count separates the two cases: a dispose before the
	// tick cancels the pending timer, so the tick does nothing and this is 0. Exactly
	// 1 means the tick ran, did its work, and THEN hit the dispose - the interleaving
	// the latch is for.
	const readsAtDispose = deltaReads()
	assert.equal(readsAtDispose, 1,
		'the dispose must have landed inside a tick that actually ran: 0 here means '
		+ 'it landed before the tick instead, and the latch is no longer under test')
	for (let window = 0; window < 3; window++) {
		tick(CADENCE)
		await flush()
		assert.equal(deltaReads(), readsAtDispose,
			'the finally must not re-arm after a dispose: that timer is untracked, so '
			+ 'no clearTimeout can ever reach it and the board polls /changes forever')
	}
})

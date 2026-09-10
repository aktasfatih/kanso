// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10299 — the board read's conditional-request half, from the CLIENT side.
//
// The server has answered `If-None-Match` with a 304 since the beginning
// (BoardController::show), and BoardControllerTest proves that branch — but it
// proves it by setting the request header itself, so it holds just as well when
// no client on earth sends one. That was exactly the state of the world: a grep
// of src/ for `If-None-Match|ETag` returned one hit and it was a comment, so
// every re-read of a board — coming back to one left open elsewhere, a delta
// resync, a mutation settling — paid the full stacks + cards + labels assembly
// the 304 exists to skip. Charter speed bet #4's board half was inert.
//
// What this file pins is the half no server-side test can see:
//   1. a refetch REPLAYS the validator from the last 200, and
//   2. the 304 that comes back does not blank the board.
//
// (2) is the part worth writing a test for. A 304 has an EMPTY body, so the
// obvious `axios.get(...).then(r => r.data)` shape returns `undefined` and
// TanStack stores `undefined` over a rendered board — a performance "win" that
// shows the user nothing at all, strictly worse than paying for the refetch. The
// assertions below therefore check the CACHE after the 304, not just the wire.
//
// Rig follows pushLiveness.test.mjs: a `window` stub before any @nextcloud
// import, dynamic imports in that order, the real composable under
// app.runWithContext, and the transport stubbed at the axios ADAPTER — so the
// real services/api.js, the real useBoard queryFn and TanStack's own refetch
// machinery all run. The adapter is a small, real conditional-GET server: it
// compares the validator and answers 304 or 200 the way BoardController does,
// and it settles its responses through `validateStatus` the way a real axios
// adapter does.

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
const { fetchBoard } = await import('../../src/services/api.js')
const { useBoard } = await import('../../src/composables/useBoard.js')
const { boardQueryKey } = await import('../../src/composables/queryKeys.js')

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
 * Read a header off an axios request config, whatever shape it is in
 * (AxiosHeaders after normalization, a plain object before it).
 *
 * @param {object} config - the axios request config the adapter received
 * @param {string} name - header name
 * @return {string|undefined} the header value, if present
 */
function header(config, name) {
	const h = config.headers
	if (!h) return undefined
	return typeof h.get === 'function' ? h.get(name) : h[name]
}

/**
 * Settle a response the way a REAL axios adapter does.
 *
 * Load-bearing, not ceremony: axios applies `validateStatus` inside its xhr/http
 * adapters (via `settle`), NOT around whatever a custom adapter returns. A stub
 * that just resolves its response would therefore treat a 304 as a success no
 * matter what the caller configured — and the single most dangerous way to get
 * this feature wrong is to forget `validateStatus` and have axios REJECT the
 * 304, turning the cheap hit into a failed query. Measured: without this
 * function, deleting `validateStatus` from fetchBoard left this whole file
 * green.
 *
 * @param {object} response - the response the fake server produced
 * @return {object} the same response, if the caller counts its status as a success
 */
function settle(response) {
	const validate = response.config.validateStatus
	if (!validate || validate(response.status)) {
		return response
	}
	const error = new Error(`Request failed with status code ${response.status}`)
	error.response = response
	throw error
}

/**
 * A miniature BoardController::show as an axios adapter.
 *
 * `latest` is the board's newest kanso_changes id — the ETag, exactly as the
 * real controller derives it. Mutating the board through `edit()` bumps it, so
 * the next validator no longer matches and the read must fall through to a full
 * 200, which is the "no stale board, ever" half of the story.
 *
 * @param {number} boardId - the board this server serves
 * @return {object} the server handle (reads log, edit(), install())
 */
function server(boardId) {
	const state = { latest: 41, title: 'before' }
	const reads = []

	const install = () => {
		axios.defaults.adapter = async (config) => {
			if (config.url.includes('/changes')) {
				// The delta poll useBoard also runs. Not this file's subject:
				// answer it with an empty, cursor-preserving delta so it never
				// patches the cache and can never be mistaken for the 304 path.
				return settle({
					status: 200,
					statusText: 'OK',
					data: { cursor: state.latest, cards: { upsert: [], remove: [] }, stacks: { upsert: [], remove: [] } },
					headers: {},
					config,
				})
			}
			const etag = `"${state.latest}"`
			const sent = header(config, 'If-None-Match')
			if (sent === etag) {
				reads.push({ conditional: true, status: 304 })
				// A 304 carries no body. Reproduced faithfully — an adapter that
				// helpfully echoed the payload back would make this whole file
				// vacuous.
				return settle({ status: 304, statusText: 'Not Modified', data: '', headers: { etag }, config })
			}
			reads.push({ conditional: sent !== undefined, status: 200 })
			return settle({
				status: 200,
				statusText: 'OK',
				data: {
					board: { id: boardId, title: state.title },
					stacks: [{ id: 10, title: 'Todo' }],
					cards: [{ id: 1, stackId: 10, sortKey: 'a', title: state.title }],
					labels: [],
					cursor: state.latest,
				},
				headers: { etag },
				config,
			})
		}
	}

	return {
		reads,
		install,
		edit: (title) => {
			state.title = title
			state.latest++
		},
	}
}

/**
 * Let every already-resolved promise chain run. `tick()` only fires timers; the
 * request they start settles in microtasks/immediates.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 30; i++) {
		await new Promise((resolve) => setImmediate(resolve))
	}
}

test('the board read replays its ETag, and a 304 hands back the payload instead of an empty body', async () => {
	const srv = server(201)
	srv.install()

	// First read: nothing to revalidate, so no validator may be sent. Sending one
	// for a payload we do not hold is the one way to get a 304 with nothing to
	// fall back on.
	const first = await fetchBoard(201)
	assert.equal(srv.reads.at(-1).conditional, false,
		'the first read of a board has no cached payload, so it must be unconditional')
	assert.equal(first.board.title, 'before')

	// Second read, this time handing over what we already hold.
	const second = await fetchBoard(201, first)
	assert.deepEqual(srv.reads.at(-1), { conditional: true, status: 304 },
		'a refetch of a board we already hold must replay the ETag from the last '
		+ '200 — without it BoardController::show can never take its 304 branch')
	assert.equal(second, first,
		'a 304 must resolve to the payload the caller already had. Returning '
		+ '`response.data` here yields undefined (a 304 has an empty body) and '
		+ 'blanks the board — worse than never sending the header at all')

	// The board changes: the validator no longer matches and the fresh payload
	// must come through and win.
	srv.edit('after')
	const third = await fetchBoard(201, second)
	assert.deepEqual(srv.reads.at(-1), { conditional: true, status: 200 },
		'a changed board must still answer 200 with the full payload')
	assert.equal(third.board.title, 'after', 'and the client must take the new payload')

	// And the validator moved with it — the next revalidation is against the new
	// ETag, not the one we started with.
	const fourth = await fetchBoard(201, third)
	assert.deepEqual(srv.reads.at(-1), { conditional: true, status: 304 })
	assert.equal(fourth.board.title, 'after')

	// A caller that holds nothing gets an unconditional read even though a
	// validator for this board is on file: a 304 would leave it with nothing.
	await fetchBoard(201)
	assert.equal(srv.reads.at(-1).conditional, false,
		'the validator may only ride a request whose caller has a payload to keep')
})

test('a read that bypassed the cache cannot validate a board the cache is still stale on', async () => {
	// The failure mode that decides how the validator is stored. Not every
	// fetchBoard result reaches the query cache: CardDetail's move-to-board
	// picker and CsvImportModal's target picker each read some OTHER board
	// unconditionally and use the payload locally (CardDetail.vue:5014,
	// CsvImportModal.vue:273).
	//
	// Keep the validator in a module-scope map keyed by board id - the obvious
	// design - and one of those reads arms a validator NEWER than whatever the
	// query cache holds for the same board. The next conditional read then
	// revalidates a stale payload successfully, gets a 304, and the user is
	// pinned to an out-of-date board with no way back: the delta cursor is
	// re-seeded from the same stale payload every time, so even a resync just
	// 304s again.
	//
	// Deriving the validator from `cached.cursor` makes that unrepresentable,
	// because the validator is a FIELD OF the payload it validates. This test is
	// what says so.
	const srv = server(203)
	srv.install()

	// What the user is looking at: the board as it was.
	const onScreen = await fetchBoard(203)
	assert.equal(onScreen.board.title, 'before')

	// The board moves on, and a picker reads it without the query cache ever
	// seeing the result.
	srv.edit('after')
	const pickerCopy = await fetchBoard(203)
	assert.equal(pickerCopy.board.title, 'after')

	// Now the board view re-reads, still holding the OLD payload.
	const next = await fetchBoard(203, onScreen)
	assert.deepEqual(srv.reads.at(-1), { conditional: true, status: 200 },
		'a stale cached payload must not be revalidated against a newer read\'s '
		+ 'ETag - it has to fall through to a full 200')
	assert.equal(next.board.title, 'after',
		'and the user must end up on the current board, not pinned to the one '
		+ 'they happened to be holding')
})

// The trigger below is an invalidation-driven refetch, NOT useBoard's 60s
// `refetchInterval`. That is deliberate: measured in a real browser, the 60s
// interval never fires at all. syncBoardDelta calls `setQueryData` on EVERY
// delta tick (useBoardDelta.js, including empty ones), each dispatch reaches
// QueryObserver.onQueryUpdate → #updateTimers, and the refetch interval is
// cleared and re-armed from zero every 5s (or 30s with push live) — always
// sooner than the 60s it is waiting for. Separate bug, not this card's; noted so
// the next reader does not build on that timer.
//
// What IS the live path for a full board re-read is exactly this: an
// invalidation. Every mutation's `onSettled` takes it, so does the delta layer's
// `resync`, and so do refetchOnMount / refetchOnWindowFocus.
test('a refetched board goes conditional and its 304 leaves the rendered board in the cache', async () => {
	const srv = server(202)
	srv.install()

	const app = createApp({})
	// main.js's own defaults, so the refetch this test drives behaves as it does
	// in the app. `retry: 1` also bounds the damage when the code under test is
	// broken: a rejected 304 otherwise retries hard enough to keep node's event
	// loop alive, and `node --test` hangs instead of reporting the failure.
	const queryClient = new QueryClient({
		defaultOptions: { queries: { staleTime: 30_000, retry: 1 } },
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	const scope = effectScope()
	scopes.push(scope)
	app.runWithContext(() => scope.run(() => useBoard(202)))
	await flush()

	const key = boardQueryKey(202)
	assert.equal(queryClient.getQueryData(key)?.board.title, 'before',
		'the initial load must populate the cache')
	const loaded = srv.reads.filter((r) => r.status === 200 || r.status === 304).length
	assert.equal(loaded, 1)

	// The board is re-read while nothing about it has changed. This is the read
	// the charter's bet is about: it must cost a validator round-trip, not the
	// whole stacks + cards + labels assembly.
	await queryClient.refetchQueries({ queryKey: key })
	await flush()

	assert.deepEqual(srv.reads.at(-1), { conditional: true, status: 304 },
		'a refetch must revalidate rather than re-download an unchanged board')
	assert.equal(queryClient.getQueryData(key)?.board.title, 'before',
		'the 304 must leave the board in the cache. If this reads undefined the '
		+ 'user is looking at a blank board the moment anything refetches it')
	// Not `status`: TanStack v5 keeps a query that already HAS data at
	// status 'success' even when a refetch throws, so the blast radius of a
	// rejected 304 hides there. The failure counter is where it shows.
	assert.equal(queryClient.getQueryState(key)?.fetchFailureCount, 0,
		'and the refetch must not have failed — a 304 is a successful conditional '
		+ 'read, not an error. Without `validateStatus` axios rejects it, and the '
		+ 'board spends the rest of the session retrying a request that is working')
	assert.equal(queryClient.getQueryState(key)?.error, null)

	// Someone else edits the board. The next read must notice.
	srv.edit('after')
	await queryClient.refetchQueries({ queryKey: key })
	await flush()

	assert.deepEqual(srv.reads.at(-1), { conditional: true, status: 200 })
	assert.equal(queryClient.getQueryData(key)?.board.title, 'after',
		'a changed board must reach the cache — a conditional read that went on '
		+ 'serving the old payload would be a permanently stale board')
	assert.equal(queryClient.getQueryData(key)?.cards[0].title, 'after')
})

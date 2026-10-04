// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10920 — a label picked while the previous one is still on the wire must be
// QUEUED, not dropped. The label twin of assigneeToggleQueue.test.mjs (#10799),
// and it pins the SAME shared queue (src/composables/useToggleQueue.js) through
// useLabels' side of it: the two composables wire that one mechanism up with
// their own mutation and their own row key, and this file is what proves the
// label wiring is live rather than inherited by assumption.
//
// The label picker stays open across picks, so adding three labels is three
// clicks in a row. The card modal used to hold a single `labelTogglePending`
// label id and return early while it was set, so those clicks sent no request
// at all: the row stayed unticked, no error appeared, and the label the user
// had just clicked simply was not on the card. Measured in the browser against
// this spec's e2e twin, three clicks with nothing awaited between them: with the
// label write delayed 800ms, 1 of 3 picks reached the server; with NO added
// latency at all, 2 of 3 — the label rows sit closer together than the assignee
// rows, so even a local round trip loses a pick.
//
// Exercised for real, in the style of assigneeToggleQueue.test.mjs: useLabels is
// a plain composable, so it runs under a Vue app context with no DOM and no
// bundler (app.runWithContext supplies the injected QueryClient without
// mounting). Only the transport is stubbed, at the axios ADAPTER — the real
// composable, the real FIFO queue, the real mutation and the real optimistic
// patch all run.
//
// `window` has to exist before @nextcloud/router is imported, and the `document`
// stub below it is the minimum TanStack's focusManager reads.

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}

// Dynamic, and in this order, on purpose: static `import` declarations are
// hoisted and evaluated BEFORE any statement in the module, so a static import
// of the composable would reach @nextcloud/router before the stub above exists.
const { createApp, effectScope, watchEffect, nextTick } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useLabels } = await import('../../src/composables/useLabels.js')
const { TOGGLE_ALREADY_PENDING } = await import('../../src/composables/useToggleQueue.js')

// TanStack's focusManager reads document.visibilityState when it decides
// whether to refetch, so it has to be present and 'visible'.
globalThis.document = {
	hidden: false,
	visibilityState: 'visible',
	addEventListener() {},
	removeEventListener() {},
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

const clients = []
after(() => {
	for (const client of clients) {
		client.unmount()
		client.clear()
	}
})

/**
 * A composable wired to its own QueryClient, a card cached with no labels, and
 * an axios adapter that records every label write and answers it after
 * `latency` ms — the in-flight window a fast clicker clicks into.
 *
 * @param {import('node:test').TestContext} t The running test.
 * @param {number} boardId Distinct per test.
 * @param {number} cardId Distinct per test.
 * @param {number} latency How long each label write takes to answer.
 * @param {Array<object|null>} outcomes Per call: a rejection value, or null to succeed.
 */
function harness(t, boardId, cardId, latency, outcomes = []) {
	const app = createApp({})
	// A SHORT gcTime, not TanStack's five-minute default, which parks a real
	// timer and keeps `node --test` from ever exiting. Not 0 either: the card
	// and board entries below have no observer, and 0 collects them before a
	// test that reads them back after the queue has drained.
	// retry:false so a refusal is one request, in one tick.
	const queryClient = new QueryClient({
		defaultOptions: {
			queries: { retry: false, gcTime: 2000 },
			mutations: { retry: false, gcTime: 0 },
		},
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	queryClient.setQueryData(['card', String(cardId)], { id: cardId, labelIds: [] })
	queryClient.setQueryData(['board', String(boardId)], {
		cards: [{ id: cardId, labelIds: [] }],
	})

	const writes = []
	let call = 0
	axios.defaults.adapter = async (config) => {
		// Only the label writes are recorded and only they are slow; any
		// settle-phase read is not what this pins.
		const match = /\/cards\/(\d+)\/labels\/(\d+)$/.exec(config.url)
		if (!match) {
			return { status: 200, statusText: 'OK', data: {}, headers: {}, config }
		}
		writes.push({ method: config.method.toUpperCase(), labelId: Number(match[2]) })
		const outcome = outcomes[call++]
		if (latency > 0) await sleep(latency)
		if (outcome) throw outcome
		return { status: 200, statusText: 'OK', data: { ok: true }, headers: {}, config }
	}

	// Inside an effectScope, like the other composable-level tests.
	const scope = effectScope()
	t.after(() => scope.stop())

	return {
		queryClient,
		writes,
		// Handed back so the reactivity test can register a watcher in the same
		// scope the composable lives in (and have it torn down with it).
		scope,
		...app.runWithContext(() => scope.run(() => useLabels(boardId))),
	}
}

/** The label ids the card detail cache currently believes are on the card. */
const cached = (queryClient, cardId) =>
	queryClient.getQueryData(['card', String(cardId)]).labelIds

test('a label picked while another is on the wire is queued, not dropped', async (t) => {
	const { enqueueToggle, writes, queryClient } = harness(t, 101, 111, 80)

	// Three clicks with no awaits between them — the interaction labels.spec.js's
	// "a label picked while the previous write is in flight is not lost" covers.
	const picks = [
		enqueueToggle({ cardId: 111, labelId: 1, assign: true }),
		enqueueToggle({ cardId: 111, labelId: 2, assign: true }),
		enqueueToggle({ cardId: 111, labelId: 3, assign: true }),
	]
	await Promise.all(picks)

	assert.deepEqual(writes.map((w) => w.labelId), [1, 2, 3],
		'every pick must reach the server, in the order it was made')
	assert.deepEqual(cached(queryClient, 111), [1, 2, 3],
		'and the cache must end up holding all three')
})

test('the label picks are serialised, not fired in parallel', async (t) => {
	// Overlapping optimistic patches is how a rollback resurrects a label a newer
	// pick already replaced, so the queue must hold the second write back until
	// the first has answered.
	const { enqueueToggle, writes } = harness(t, 102, 222, 120)

	const first = enqueueToggle({ cardId: 222, labelId: 1, assign: true })
	enqueueToggle({ cardId: 222, labelId: 2, assign: true })

	await sleep(40)
	assert.deepEqual(writes.map((w) => w.labelId), [1],
		'the second pick must still be waiting its turn')

	await first
	await sleep(40)
	assert.deepEqual(writes.map((w) => w.labelId), [1, 2],
		'and must go out once the first has answered')
})

test('clicking the SAME label twice while it writes is still one write', async (t) => {
	// The double-submit the pending flag was introduced for (#10705): `aria-busy`
	// leaves the row in the focus order, so a held-down Enter repeats it.
	const { enqueueToggle, writes, isTogglePending } = harness(t, 103, 333, 80)

	const first = enqueueToggle({ cardId: 333, labelId: 7, assign: true })
	assert.equal(isTogglePending(333, 7), true, 'the row must report itself busy')
	const repeat = await enqueueToggle({ cardId: 333, labelId: 7, assign: true })

	assert.equal(repeat, TOGGLE_ALREADY_PENDING,
		'a repeat of the row being written must be reported as sent-nothing, so the '
		+ 'modal does not announce a label change that never happened')
	await first
	assert.deepEqual(writes.map((w) => w.labelId), [7], 'exactly one write')
	assert.equal(isTogglePending(333, 7), false, 'and the row must go idle again')
})

test('one busy label row does not report the OTHER rows busy', async (t) => {
	// The flag this replaced was a single id for the whole picker, so while one
	// row wrote, `aria-busy` was the only thing that stayed per-row - the guard
	// itself refused everything. Per-row is the whole correction.
	const { enqueueToggle, isTogglePending } = harness(t, 104, 444, 80)

	const first = enqueueToggle({ cardId: 444, labelId: 1, assign: true })
	assert.equal(isTogglePending(444, 2), false, 'a different label must not read as busy')
	assert.equal(isTogglePending(999, 1), false, 'nor the same label on a different card')
	await first
})

test('the pending flag is REACTIVE, which is what repaints aria-busy', async (t) => {
	// The template reads isTogglePending to set `aria-busy` on the one row being
	// written (CardDetail.vue, the label rows and the assignee rows both). That
	// only works because the Set lives in a `ref`, so Vue's collection handlers
	// instrument has/add/delete. Swapping the ref for a plain `const pending =
	// new Set()` keeps every OTHER test in both queue files green while silently
	// killing every aria-busy in both pickers — the accessibility behaviour
	// #10705 exists for. So assert the re-render, not just the return value.
	const { enqueueToggle, isTogglePending, scope } = harness(t, 106, 666, 80)

	const seen = []
	scope.run(() => watchEffect(() => seen.push(isTogglePending(666, 1))))
	assert.deepEqual(seen, [false], 'the row starts idle')

	const pick = enqueueToggle({ cardId: 666, labelId: 1, assign: true })
	await nextTick()
	assert.deepEqual(seen, [false, true],
		'starting the toggle must re-run a watcher reading that row')

	await pick
	await nextTick()
	assert.deepEqual(seen, [false, true, false],
		'and finishing it must re-run the watcher again')
})

test('a refused label pick does not strand the picks queued behind it', async (t) => {
	const { enqueueToggle, writes } = harness(t, 105, 555, 40, [
		{ response: { status: 403, data: { error: 'Nope.' } } },
	])

	const refused = enqueueToggle({ cardId: 555, labelId: 1, assign: true })
	const next = enqueueToggle({ cardId: 555, labelId: 2, assign: true })

	await assert.rejects(() => refused, 'the refused pick must still reject to its own caller')
	await next

	assert.deepEqual(writes.map((w) => w.labelId), [1, 2],
		'the queue must carry on after a refusal')
})

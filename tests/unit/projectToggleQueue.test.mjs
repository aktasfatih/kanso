// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10922 — a project picked while the previous one is still on the wire must be
// QUEUED, not dropped. The third file in the family, after
// assigneeToggleQueue.test.mjs (#10799) and labelToggleQueue.test.mjs (#10920),
// and it pins the SAME shared queue (src/composables/useToggleQueue.js) through
// useCardProjects' side of it: the three composables wire that one mechanism up
// with their own mutation and their own row key, and this file is what proves
// the projects wiring is live rather than inherited by assumption.
//
// The defect here was NOT the other two's, which is why it needed its own
// measurement. Those pickers held a single pending id for the whole list and
// returned early from the handler while it was set. The projects picker never
// had such a guard at all (CardDetail.vue's handler had no early return): it
// bound its whole-picker pending boolean to `:disabled` on EVERY row, and a
// browser does not deliver a click to a disabled button — so the pick was eaten
// one layer BELOW the JavaScript, which is also why the same attribute blurred
// the focused row (#10705). Measured in the browser, three project rows clicked
// with nothing awaited between them (real trusted mouse clicks):
//   - as shipped: 1 of 3 picks reached the server with the write delayed 800ms,
//     and 1 of 3 with NO added latency at all — the worst of the three pickers.
//   - the same three clicks dispatched past the `disabled` attribute: 3 of 3 at
//     both latencies, pinning that attribute as the whole cause.
//   - with the fix (aria-busy + this queue): 3 of 3 at both latencies.
//
// Dropping `disabled` is therefore what stops the drop, and it is also what
// re-opens the double-submit `disabled` was covering, since an `aria-busy` row
// stays in the focus order and a held-down Enter repeats it. That guard is the
// queue's, per row — which is what the tests below are mostly about.
//
// Exercised for real, in the style of its two siblings: useCardProjects is a
// plain composable, so it runs under a Vue app context with no DOM and no
// bundler (app.runWithContext supplies the injected QueryClient without
// mounting). Only the transport is stubbed, at the axios ADAPTER — the real
// composable, the real FIFO queue and the real mutation all run.
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
const { createApp, effectScope, watchEffect, nextTick, ref } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useCardProjects } = await import('../../src/composables/useProjects.js')
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
 * A composable wired to its own QueryClient and an axios adapter that records
 * every project-membership write and answers it after `latency` ms — the
 * in-flight window a fast clicker clicks into.
 *
 * `openCard` stands in for the modal's `props.cardId` — the card that happens to
 * be on screen. It is handed to useCardProjects DELIBERATELY, even though the
 * composable takes no argument: "the composable must not bind a card id, even
 * when it is given one" is itself an assertion, and the only way the regression
 * below (reading a composable-level id at write time instead of the pick's own
 * `vars.cardId`) stays reachable from a test.
 *
 * @param {import('node:test').TestContext} t The running test.
 * @param {import('vue').Ref<number>|number} openCard The card on screen, reactive
 *   or plain. Distinct per test.
 * @param {number} latency How long each membership write takes to answer.
 * @param {Array<object|null>} outcomes Per call: a rejection value, or null to succeed.
 */
function harness(t, openCard, latency, outcomes = []) {
	const app = createApp({})
	// A SHORT gcTime, not TanStack's five-minute default, which parks a real
	// timer and keeps `node --test` from ever exiting. retry:false so a refusal
	// is one request, in one tick.
	const queryClient = new QueryClient({
		defaultOptions: {
			queries: { retry: false, gcTime: 2000 },
			mutations: { retry: false, gcTime: 0 },
		},
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	// Every key the composable asks to invalidate, flattened. A write going to the
	// right card is only half of a settle: the detail key it refreshes afterwards
	// has to be that same card's, or the picker's ticks are reconciled on one card
	// and left stale on the other.
	const invalidated = []
	const realInvalidate = queryClient.invalidateQueries.bind(queryClient)
	queryClient.invalidateQueries = (filters, options) => {
		invalidated.push((filters?.queryKey ?? []).join('/'))
		return realInvalidate(filters, options)
	}

	const writes = []
	let call = 0
	axios.defaults.adapter = async (config) => {
		// Only the membership writes are recorded and only they are slow; any
		// settle-phase read is not what this pins.
		const match = /\/projects\/(\d+)\/cards\/(\d+)$/.exec(config.url)
		if (!match) {
			return { status: 200, statusText: 'OK', data: {}, headers: {}, config }
		}
		writes.push({
			method: config.method.toUpperCase(),
			projectId: Number(match[1]),
			cardId: Number(match[2]),
		})
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
		invalidated,
		// Handed back so the reactivity test can register a watcher in the same
		// scope the composable lives in (and have it torn down with it).
		scope,
		...app.runWithContext(() => scope.run(() => useCardProjects(openCard))),
	}
}

test('a project picked while another is on the wire is queued, not dropped', async (t) => {
	const { enqueueToggle, writes } = harness(t, 111, 80)

	// Three clicks with no awaits between them — the interaction projects.spec.js's
	// "a project picked while the previous write is in flight is not lost" covers.
	const picks = [
		enqueueToggle({ cardId: 111, projectId: 1, assign: true }),
		enqueueToggle({ cardId: 111, projectId: 2, assign: true }),
		enqueueToggle({ cardId: 111, projectId: 3, assign: true }),
	]
	await Promise.all(picks)

	assert.deepEqual(writes.map((w) => w.projectId), [1, 2, 3],
		'every pick must reach the server, in the order it was made')
	assert.deepEqual(writes.map((w) => w.method), ['PUT', 'PUT', 'PUT'],
		'and as adds, since every row started unticked')
	assert.deepEqual(writes.map((w) => w.cardId), [111, 111, 111],
		'all against the card the picker was opened on')
})

test('the project picks are serialised, not fired in parallel', async (t) => {
	// The three pickers share one queue, and for the other two serial is load
	// bearing: overlapping optimistic patches are how a rollback resurrects a
	// value a newer pick already replaced. This picker has no optimistic patch,
	// so serial here is what keeps its settle invalidations — and so the ticks
	// the user sees — in the order the picks were made.
	const { enqueueToggle, writes } = harness(t, 222, 120)

	const first = enqueueToggle({ cardId: 222, projectId: 1, assign: true })
	enqueueToggle({ cardId: 222, projectId: 2, assign: true })

	await sleep(40)
	assert.deepEqual(writes.map((w) => w.projectId), [1],
		'the second pick must still be waiting its turn')

	await first
	await sleep(40)
	assert.deepEqual(writes.map((w) => w.projectId), [1, 2],
		'and must go out once the first has answered')
})

test('clicking the SAME project twice while it writes is still one write', async (t) => {
	// The double-submit `disabled` was covering (#10705): `aria-busy` leaves the
	// row in the focus order, so a held-down Enter repeats it. On this picker that
	// guard did not exist in any form before — `disabled` was the whole of it —
	// so this assertion is the one that keeps removing the attribute from being a
	// regression.
	const { enqueueToggle, writes, isTogglePending } = harness(t, 333, 80)

	const first = enqueueToggle({ cardId: 333, projectId: 7, assign: true })
	assert.equal(isTogglePending(333, 7), true, 'the row must report itself busy')
	const repeat = await enqueueToggle({ cardId: 333, projectId: 7, assign: true })

	assert.equal(repeat, TOGGLE_ALREADY_PENDING,
		'a repeat of the row being written must be reported as sent-nothing, so the '
		+ 'modal does not surface an outcome for a write that never happened')
	await first
	assert.deepEqual(writes.map((w) => w.projectId), [7], 'exactly one write')
	assert.equal(isTogglePending(333, 7), false, 'and the row must go idle again')
})

test('one busy project row does not report the OTHER rows busy', async (t) => {
	// `disabled` was bound to a single whole-picker boolean, so while one row
	// wrote, EVERY row was dead. Per-row is the whole correction.
	const { enqueueToggle, isTogglePending } = harness(t, 444, 80)

	const first = enqueueToggle({ cardId: 444, projectId: 1, assign: true })
	assert.equal(isTogglePending(444, 2), false, 'a different project must not read as busy')
	assert.equal(isTogglePending(999, 1), false, 'nor the same project on a different card')
	await first
})

test('the pending flag is REACTIVE, which is what repaints aria-busy', async (t) => {
	// The template reads isProjectTogglePending to set `aria-busy` on the one row
	// being written. That only works because the queue's Set lives in a `ref`, so
	// Vue's collection handlers instrument has/add/delete. Swapping the ref for a
	// plain `const pending = new Set()` keeps every OTHER test in all three queue
	// files green while silently killing every aria-busy in all three pickers —
	// the accessibility behaviour #10705 exists for. So assert the re-render, not
	// just the return value.
	const { enqueueToggle, isTogglePending, scope } = harness(t, 666, 80)

	const seen = []
	scope.run(() => watchEffect(() => seen.push(isTogglePending(666, 1))))
	assert.deepEqual(seen, [false], 'the row starts idle')

	const pick = enqueueToggle({ cardId: 666, projectId: 1, assign: true })
	await nextTick()
	assert.deepEqual(seen, [false, true],
		'starting the toggle must re-run a watcher reading that row')

	await pick
	await nextTick()
	assert.deepEqual(seen, [false, true, false],
		'and finishing it must re-run the watcher again')
})

test('a refused project pick does not strand the picks queued behind it', async (t) => {
	// A project is the viewer's own collection, so a refusal is a live case here:
	// the card can stop being readable between opening the picker and clicking.
	const { enqueueToggle, writes } = harness(t, 555, 40, [
		{ response: { status: 403, data: { error: 'Nope.' } } },
	])

	const refused = enqueueToggle({ cardId: 555, projectId: 1, assign: true })
	const next = enqueueToggle({ cardId: 555, projectId: 2, assign: true })

	await assert.rejects(() => refused, 'the refused pick must still reject to its own caller')
	await next

	assert.deepEqual(writes.map((w) => w.projectId), [1, 2],
		'the queue must carry on after a refusal')
})

test('a pick queued before you open another card still lands on the card it was made for', async (t) => {
	// The hazard the QUEUE opened. Before it, the write left in the click handler,
	// so the card on screen and the card being written were the same thing by
	// construction. A queued pick outlives that: the modal is rendered through an
	// UNKEYED router-view, so navigating card→card REUSES the component and
	// `props.cardId` changes under the live composable (CardDetail.vue's cardId
	// watcher is there for exactly that reuse). useCardProjects used to read a
	// composable-level `id.value` inside mutationFn and onSettled - i.e. at
	// EXECUTION time - so a pick waiting its turn was sent for whichever card had
	// been opened by then, and the settle refreshed that card's key too. The pick
	// is not merely lost at that point: it is applied to a card the user never
	// touched. Its own queue key already used `vars.cardId` (`keyOf`), so the row
	// the queue thought it was writing and the row it wrote disagreed.
	//
	// Its two siblings take cardId from the mutation vars already
	// (useLabels.js:94, useAssignees.js:89); this was the outlier.
	const openCard = ref(888)
	const { enqueueToggle, writes, invalidated } = harness(t, openCard, 80)

	const first = enqueueToggle({ cardId: 888, projectId: 1, assign: true })
	const queued = enqueueToggle({ cardId: 888, projectId: 2, assign: true })

	await sleep(20)
	assert.deepEqual(writes.map((w) => w.projectId), [1],
		'the second pick must still be waiting its turn - otherwise the switch below '
		+ 'happens after it has already gone out and the test proves nothing')

	// The user opens another card while that second pick is still queued.
	openCard.value = 999

	await Promise.all([first, queued])
	await sleep(20)

	assert.deepEqual(writes, [
		{ method: 'PUT', projectId: 1, cardId: 888 },
		{ method: 'PUT', projectId: 2, cardId: 888 },
	], 'both picks must be written against card 888 - the card whose picker they '
		+ 'were made in - not against whatever card is open when they execute')

	assert.ok(invalidated.includes('card/888'),
		'and the settle must refresh card 888, whose projectIds actually changed')
	assert.ok(!invalidated.includes('card/999'),
		'never the card merely open at the time: refreshing it leaves 888 stale and '
		+ 'spends a request re-reading a card nothing wrote to')
})

test('un-picking a project sends the remove, and queues behind an add', async (t) => {
	// `assign` is read at CLICK time in the handler, not when the write starts, so
	// a pick queued behind another one still means what the user saw. This is the
	// assertion that the flag survives the queue rather than being re-derived from
	// a cache the earlier write has since changed.
	const { enqueueToggle, writes } = harness(t, 777, 60)

	const add = enqueueToggle({ cardId: 777, projectId: 1, assign: true })
	const remove = enqueueToggle({ cardId: 777, projectId: 2, assign: false })
	await Promise.all([add, remove])

	assert.deepEqual(writes, [
		{ method: 'PUT', projectId: 1, cardId: 777 },
		{ method: 'DELETE', projectId: 2, cardId: 777 },
	], 'the queued pick must keep its own direction')
})

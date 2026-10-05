// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10799 — a pick taken while the previous one is still on the wire must be
// QUEUED, not dropped.
//
// The assignee picker stays open across picks (#10603), so adding a second and
// third person is three clicks in a row, and three clicks beat one round trip on
// anything but a fast link. The modal used to hold a single "a toggle is
// pending" uid and return early while it was set, so those picks sent no request
// at all: the row stayed unticked, no error appeared, and the person the user
// had just picked simply was not on the card. Measured in the browser against
// this spec's e2e twin: with the assign write delayed 1200ms, 2 of 3 picks
// reached the server; with no added latency, 3 of 3. That is the flakiness of
// card-multi-assign.spec.js's three-pick test, and a real loss of a user's
// action.
//
// The queue itself now lives in src/composables/useToggleQueue.js, shared with
// the label picker, which had the identical defect (#10920). This file pins
// useAssignees' side of that wiring; labelToggleQueue.test.mjs pins the other.
//
// Exercised for real, in the style of cardMoveQueue.test.mjs: useAssignees is a
// plain composable, so it runs under a Vue app context with no DOM and no
// bundler (app.runWithContext supplies the injected QueryClient without
// mounting). Only the transport is stubbed, at the axios ADAPTER — the real
// composable, the real FIFO queue, the real mutation and the real optimistic
// patch all run.
//
// `window` has to exist before @nextcloud/router is imported, and the `document`
// stub below it is the minimum TanStack's focusManager reads - both exactly as
// aclParticipantsInvalidation.test.mjs sets them up. Neither is a DOM; nothing
// here mounts a component.

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
const { createApp, effectScope } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useAssignees } = await import('../../src/composables/useAssignees.js')
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
 * A composable wired to its own QueryClient, a card cached with no assignees,
 * and an axios adapter that records every assignee write and answers it after
 * `latency` ms — the in-flight window a fast clicker clicks into.
 *
 * @param {import('node:test').TestContext} t The running test.
 * @param {number} boardId Distinct per test.
 * @param {number} cardId Distinct per test.
 * @param {number} latency How long each assignee write takes to answer.
 * @param {Array<object|null>} outcomes Per call: a rejection value, or null to succeed.
 */
function harness(t, boardId, cardId, latency, outcomes = []) {
	const app = createApp({})
	// A SHORT gcTime, not TanStack's five-minute default, which parks a real
	// timer and keeps `node --test` from ever exiting (that is why
	// aclParticipantsInvalidation.test.mjs uses 0). Not 0 here: the card and
	// board entries below have no observer, and 0 collects them before a test
	// that reads them back after the queue has drained. 2s outlives every test
	// in this file and costs that much at the end of the run, once.
	// retry:false so a refusal is one request, in one tick.
	const queryClient = new QueryClient({
		defaultOptions: {
			queries: { retry: false, gcTime: 2000 },
			mutations: { retry: false, gcTime: 0 },
		},
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	queryClient.setQueryData(['card', String(cardId)], { id: cardId, assigneeIds: [] })
	queryClient.setQueryData(['board', String(boardId)], {
		cards: [{ id: cardId, assigneeIds: [] }],
	})

	const writes = []
	let call = 0
	axios.defaults.adapter = async (config) => {
		// Participants and any settle-phase read are not what this pins; only the
		// assignee writes are recorded and only they are slow.
		const match = /\/cards\/(\d+)\/assignees\/([^/?]+)$/.exec(config.url)
		if (!match) {
			return { status: 200, statusText: 'OK', data: {}, headers: {}, config }
		}
		writes.push({ method: config.method.toUpperCase(), userId: match[2] })
		const outcome = outcomes[call++]
		if (latency > 0) await sleep(latency)
		if (outcome) throw outcome
		return { status: 200, statusText: 'OK', data: { ok: true }, headers: {}, config }
	}

	// Inside an effectScope, like the other composable-level tests: that is what
	// subscribes the query observer, and stopping it releases the subscription.
	const scope = effectScope()
	t.after(() => scope.stop())

	return {
		queryClient,
		writes,
		...app.runWithContext(() => scope.run(() => useAssignees(boardId))),
	}
}

/** The uids the card detail cache currently believes are assigned. */
const cached = (queryClient, cardId) =>
	queryClient.getQueryData(['card', String(cardId)]).assigneeIds

test('a pick taken while another is on the wire is queued, not dropped', async (t) => {
	const { enqueueToggle, writes, queryClient } = harness(t, 1, 11, 80)

	// Three clicks with no awaits between them — the interaction
	// card-multi-assign.spec.js's three-pick test exists to cover.
	const picks = [
		enqueueToggle({ cardId: 11, userId: 'alice', assign: true }),
		enqueueToggle({ cardId: 11, userId: 'bob', assign: true }),
		enqueueToggle({ cardId: 11, userId: 'carol', assign: true }),
	]
	await Promise.all(picks)

	assert.deepEqual(writes.map((w) => w.userId), ['alice', 'bob', 'carol'],
		'every pick must reach the server, in the order it was made')
	assert.deepEqual(cached(queryClient, 11), ['alice', 'bob', 'carol'],
		'and the cache must end up holding all three')
})

test('the picks are serialised, not fired in parallel', async (t) => {
	// Overlapping optimistic patches is how a rollback resurrects a value a newer
	// pick already replaced, so the queue must hold the second write back until
	// the first has answered.
	const { enqueueToggle, writes } = harness(t, 2, 22, 120)

	const first = enqueueToggle({ cardId: 22, userId: 'alice', assign: true })
	enqueueToggle({ cardId: 22, userId: 'bob', assign: true })

	await sleep(40)
	assert.deepEqual(writes.map((w) => w.userId), ['alice'],
		'the second pick must still be waiting its turn')

	await first
	await sleep(40)
	assert.deepEqual(writes.map((w) => w.userId), ['alice', 'bob'],
		'and must go out once the first has answered')
})

test('clicking the SAME row twice while it writes is still one write', async (t) => {
	// The double-submit the pending flag was introduced for (#10705): `aria-busy`
	// leaves the row in the focus order, so a held-down Enter repeats it.
	const { enqueueToggle, writes, isTogglePending } = harness(t, 3, 33, 80)

	const first = enqueueToggle({ cardId: 33, userId: 'alice', assign: true })
	assert.equal(isTogglePending(33, 'alice'), true, 'the row must report itself busy')
	const repeat = await enqueueToggle({ cardId: 33, userId: 'alice', assign: true })

	assert.equal(repeat, TOGGLE_ALREADY_PENDING,
		'a repeat of the row being written must be reported as sent-nothing, so the '
		+ 'modal does not announce an assignment that never happened')
	await first
	assert.deepEqual(writes.map((w) => w.userId), ['alice'], 'exactly one write')
	assert.equal(isTogglePending(33, 'alice'), false, 'and the row must go idle again')
})

test('one busy assignee row does not report the OTHER rows busy', async (t) => {
	// The flag this replaced was a single uid for the whole picker, so while one
	// row wrote, the guard refused every row. Per-row is the whole correction —
	// pinned on both sides of the shared queue (labelToggleQueue.test.mjs has
	// the label twin of this test).
	const { enqueueToggle, isTogglePending } = harness(t, 5, 55, 80)

	const first = enqueueToggle({ cardId: 55, userId: 'alice', assign: true })
	assert.equal(isTogglePending(55, 'bob'), false, 'a different person must not read as busy')
	assert.equal(isTogglePending(999, 'alice'), false, 'nor the same person on a different card')
	await first
})

test('a refused pick does not strand the picks queued behind it', async (t) => {
	const { enqueueToggle, writes } = harness(t, 4, 44, 40, [
		{ response: { status: 403, data: { error: 'Nope.' } } },
	])

	const refused = enqueueToggle({ cardId: 44, userId: 'alice', assign: true })
	const next = enqueueToggle({ cardId: 44, userId: 'bob', assign: true })

	await assert.rejects(() => refused, 'the refused pick must still reject to its own caller')
	await next

	assert.deepEqual(writes.map((w) => w.userId), ['alice', 'bob'],
		'the queue must carry on after a refusal')
})

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10923 - a FAILED write in one attribute picker must not revert what ANOTHER
// picker already committed.
//
// The three pickers in the card modal's attribute bar each have their own FIFO
// queue (src/composables/useToggleQueue.js), so a label write and an assignee
// write genuinely overlap - that is the point of keeping them independent. Both
// optimistic mutations used to snapshot the WHOLE ['card', id] object (and the
// whole board object) in onMutate and re-set it wholesale in onError, so the
// loser of a race took the winner down with it: pick a label, pick a person
// while the label write is still on the wire, let the label write fail, and the
// rollback restored a card object from before the person existed. The person was
// on the server; the client said otherwise, with nothing shown to the user.
//
// Each rollback now restores only the ONE array it owns, layered onto whatever
// the cache holds at rollback time. Serialisation per picker is what makes that
// safe: no two label writes overlap, so a label snapshot can only ever be stale
// with respect to a DIFFERENT field.
//
// Measured the same way as labelToggleQueue.test.mjs: a real QueryClient, the
// real composables, the real mutations and the real optimistic patches, with
// only the axios adapter stubbed. The settle invalidations cannot heal anything
// here because the seeded cache entries have no queryFn and no observer - which
// is also the real case in which the lie PERSISTS (the modal closed, or the
// network that failed the write failing the refetch too).

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}

// Dynamic, and in this order, on purpose - see labelToggleQueue.test.mjs:41.
const { createApp, effectScope } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useLabels } = await import('../../src/composables/useLabels.js')
const { useAssignees } = await import('../../src/composables/useAssignees.js')

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

const REFUSED = { response: { status: 403, data: { error: 'Nope.' } } }

/**
 * Both pickers on ONE QueryClient and one cached card - the card modal's own
 * arrangement, which is what lets their writes interleave.
 *
 * @param {import('node:test').TestContext} t The running test.
 * @param {number} boardId Distinct per test.
 * @param {number} cardId Distinct per test.
 * @param {object} plan Per-endpoint latency and outcome.
 * @param {number} plan.labelLatency How long a label write takes to answer.
 * @param {object|null} plan.labelOutcome Rejection value for the label write, or null.
 * @param {number} plan.assigneeLatency How long an assignee write takes to answer.
 * @param {object|null} plan.assigneeOutcome Rejection value for the assignee write, or null.
 */
function harness(t, boardId, cardId, plan) {
	const {
		labelLatency = 0,
		labelOutcome = null,
		assigneeLatency = 0,
		assigneeOutcome = null,
	} = plan
	const app = createApp({})
	const queryClient = new QueryClient({
		defaultOptions: {
			queries: { retry: false, gcTime: 2000 },
			mutations: { retry: false, gcTime: 0 },
		},
	})
	app.use(VueQueryPlugin, { queryClient })
	clients.push(queryClient)

	// The card the user is editing, plus a SECOND card in the board summary: a
	// whole-board rollback reverts every card in the cache, not just this one.
	queryClient.setQueryData(['card', String(cardId)], {
		id: cardId, title: 'Card', labelIds: [], assigneeIds: [],
	})
	queryClient.setQueryData(['board', String(boardId)], {
		cards: [
			{ id: cardId, title: 'Card', labelIds: [], assigneeIds: [] },
			{ id: cardId + 1, title: 'Neighbour', labelIds: [], assigneeIds: [] },
		],
	})

	const writes = []
	axios.defaults.adapter = async (config) => {
		const label = /\/cards\/(\d+)\/labels\/(\d+)$/.exec(config.url)
		const assignee = /\/cards\/(\d+)\/assignees\/([^/]+)$/.exec(config.url)
		if (label) {
			writes.push({ kind: 'label', id: Number(label[2]) })
			if (labelLatency > 0) await sleep(labelLatency)
			if (labelOutcome) throw labelOutcome
		} else if (assignee) {
			writes.push({ kind: 'assignee', id: assignee[2] })
			if (assigneeLatency > 0) await sleep(assigneeLatency)
			if (assigneeOutcome) throw assigneeOutcome
		}
		return { status: 200, statusText: 'OK', data: { ok: true }, headers: {}, config }
	}

	const scope = effectScope()
	t.after(() => scope.stop())

	const { labels, assignees } = app.runWithContext(() => scope.run(() => ({
		labels: useLabels(boardId),
		assignees: useAssignees(boardId),
	})))

	return { queryClient, writes, labels, assignees }
}

/** What the card detail cache - the modal's chips - currently believes. */
const detail = (queryClient, cardId) => queryClient.getQueryData(['card', String(cardId)])
/** The same card as the board summary cache - the tile's chips - holds it. */
const summary = (queryClient, boardId, cardId) =>
	queryClient.getQueryData(['board', String(boardId)]).cards.find((c) => c.id === cardId)

test('a FAILED label write must not revert an assignee added while it was in flight', async (t) => {
	const { queryClient, writes, labels, assignees } = harness(t, 201, 2001, {
		labelLatency: 120,
		labelOutcome: REFUSED,
	})

	// Pick a label...
	const labelPick = labels.enqueueToggle({ cardId: 2001, labelId: 7, assign: true })
	// ...and a person while that label write is still on the wire. Different
	// picker, different queue, so this one is NOT queued behind it.
	await assignees.enqueueToggle({ cardId: 2001, userId: 'alice', assign: true })

	assert.deepEqual(writes, [{ kind: 'label', id: 7 }, { kind: 'assignee', id: 'alice' }],
		'both writes must have gone out, the assignee one during the label one')
	assert.deepEqual(detail(queryClient, 2001).assigneeIds, ['alice'],
		'the assignee write succeeded, so the client holds it')

	// Now the label write is refused.
	await assert.rejects(() => labelPick, 'the label pick must reject to its own caller')
	await sleep(20)

	assert.deepEqual(detail(queryClient, 2001).assigneeIds, ['alice'],
		'the failed label rollback must leave the committed assignee alone - the '
		+ 'server has it, so erasing it client-side makes the client lie')
	assert.deepEqual(detail(queryClient, 2001).labelIds, [],
		'while still rolling back the label it owns')
	assert.deepEqual(summary(queryClient, 201, 2001).assigneeIds, ['alice'],
		'same in the board summary cache, which the card tile renders from')
	assert.deepEqual(summary(queryClient, 201, 2001).labelIds, [])
})

test('and a FAILED assignee write must not revert a label added while it was in flight', async (t) => {
	// The mirror direction - the rollback is over-broad in both composables, so
	// fixing one and inheriting the other by assumption is how half of this
	// stays broken.
	const { queryClient, writes, labels, assignees } = harness(t, 202, 2002, {
		assigneeLatency: 120,
		assigneeOutcome: REFUSED,
	})

	const assigneePick = assignees.enqueueToggle({ cardId: 2002, userId: 'bob', assign: true })
	await labels.enqueueToggle({ cardId: 2002, labelId: 9, assign: true })

	assert.deepEqual(writes, [{ kind: 'assignee', id: 'bob' }, { kind: 'label', id: 9 }])
	await assert.rejects(() => assigneePick)
	await sleep(20)

	assert.deepEqual(detail(queryClient, 2002).labelIds, [9],
		'the label write succeeded, so the failed assignee rollback must not undo it')
	assert.deepEqual(detail(queryClient, 2002).assigneeIds, [],
		'while still rolling back the assignee it owns')
	assert.deepEqual(summary(queryClient, 202, 2002).labelIds, [9])
	assert.deepEqual(summary(queryClient, 202, 2002).assigneeIds, [])
})

test('a failed label write rolls back its OWN field with nothing else in flight', async (t) => {
	// The narrowing must not become "no rollback at all": with no competing write
	// the optimistic tick still has to come back off.
	const { queryClient, labels } = harness(t, 203, 2003, {
		labelLatency: 20,
		labelOutcome: REFUSED,
	})

	queryClient.setQueryData(['card', '2003'], (old) => ({ ...old, labelIds: [1] }))
	queryClient.setQueryData(['board', '203'], (old) => ({
		...old,
		cards: old.cards.map((c) => (c.id === 2003 ? { ...c, labelIds: [1] } : c)),
	}))

	await assert.rejects(() => labels.enqueueToggle({ cardId: 2003, labelId: 2, assign: true }))
	await sleep(20)

	assert.deepEqual(detail(queryClient, 2003).labelIds, [1],
		'the refused label must be off the card again, and the one it already had kept')
	assert.deepEqual(summary(queryClient, 203, 2003).labelIds, [1])
})

test('a failed label write does not revert the REST of the board cache either', async (t) => {
	// The board snapshot is the whole board object - every card in it. The board
	// delta subscription and the user's own moves both write into this cache
	// while a label write is on the wire, and a whole-object restore reverts all
	// of it.
	const { queryClient, labels } = harness(t, 204, 2004, {
		labelLatency: 120,
		labelOutcome: REFUSED,
	})

	const pick = labels.enqueueToggle({ cardId: 2004, labelId: 3, assign: true })
	// AFTER the snapshot, not with it: `enqueue` chains onto a promise, so the
	// mutation's onMutate runs in a microtask - writing the cache in the same
	// synchronous block as the pick lands BEFORE the snapshot and makes this
	// assertion vacuous (it passed that way even unfixed).
	await sleep(20)
	assert.deepEqual(summary(queryClient, 204, 2004).labelIds, [3],
		'the optimistic patch must be in the cache, i.e. the snapshot is taken')
	// A delta arrives for a different card while the label write is in flight.
	queryClient.setQueryData(['board', '204'], (old) => ({
		...old,
		cards: old.cards.map((c) => (c.id === 2005 ? { ...c, title: 'Renamed elsewhere' } : c)),
	}))

	await assert.rejects(() => pick)
	await sleep(20)

	assert.equal(summary(queryClient, 204, 2005).title, 'Renamed elsewhere',
		'a refused label on one card must not rewind another card in the board cache')
})

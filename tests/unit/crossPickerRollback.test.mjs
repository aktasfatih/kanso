// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10923 / #10927 - a FAILED write in one attribute picker must not revert what
// ANOTHER picker already committed.
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
// The priority and card-type pickers in the same bar had the identical rollback
// (#10927), and no queue at all between them: setting a priority and then a type
// fires two overlapping PATCHes on the same card, so either one failing used to
// wipe the other out - and the board-level snapshot took every OTHER card in the
// cache with it.
//
// Each rollback now restores only the ONE field it owns, layered onto whatever
// the cache holds at rollback time. Serialisation per picker is what makes that
// safe for labels and assignees: no two label writes overlap, so a label
// snapshot can only ever be stale with respect to a DIFFERENT field. Priority
// and type are single-valued, so the narrow restore is last-write-wins on their
// own field either way - no worse than the whole-object restore was, and no
// longer destructive to anything else.
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
const { usePriority } = await import('../../src/composables/usePriority.js')
const { useCardType } = await import('../../src/composables/useCardType.js')

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
 * All four pickers on ONE QueryClient and one cached card - the card modal's own
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
 * @param {number} plan.priorityLatency How long a priority write takes to answer.
 * @param {object|null} plan.priorityOutcome Rejection value for the priority write, or null.
 * @param {number} plan.typeLatency How long a card-type write takes to answer.
 * @param {object|null} plan.typeOutcome Rejection value for the card-type write, or null.
 */
function harness(t, boardId, cardId, plan) {
	const {
		labelLatency = 0,
		labelOutcome = null,
		assigneeLatency = 0,
		assigneeOutcome = null,
		priorityLatency = 0,
		priorityOutcome = null,
		typeLatency = 0,
		typeOutcome = null,
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
		id: cardId, title: 'Card', labelIds: [], assigneeIds: [], priority: 0, type: '',
	})
	queryClient.setQueryData(['board', String(boardId)], {
		cards: [
			{ id: cardId, title: 'Card', labelIds: [], assigneeIds: [], priority: 0, type: '' },
			{ id: cardId + 1, title: 'Neighbour', labelIds: [], assigneeIds: [], priority: 0, type: '' },
		],
	})

	const writes = []
	axios.defaults.adapter = async (config) => {
		const label = /\/cards\/(\d+)\/labels\/(\d+)$/.exec(config.url)
		const assignee = /\/cards\/(\d+)\/assignees\/([^/]+)$/.exec(config.url)
		// Priority and card type share ONE endpoint - a PATCH on the card - so the
		// two are told apart by which field the body carries, exactly as the server
		// does. config.data is already serialised by the time an adapter sees it.
		const cardPatch = /\/cards\/(\d+)$/.exec(config.url)
		if (label) {
			writes.push({ kind: 'label', id: Number(label[2]) })
			if (labelLatency > 0) await sleep(labelLatency)
			if (labelOutcome) throw labelOutcome
		} else if (assignee) {
			writes.push({ kind: 'assignee', id: assignee[2] })
			if (assigneeLatency > 0) await sleep(assigneeLatency)
			if (assigneeOutcome) throw assigneeOutcome
		} else if (cardPatch) {
			const body = typeof config.data === 'string' ? JSON.parse(config.data) : (config.data ?? {})
			if ('priority' in body) {
				writes.push({ kind: 'priority', id: body.priority })
				if (priorityLatency > 0) await sleep(priorityLatency)
				if (priorityOutcome) throw priorityOutcome
			} else if ('type' in body) {
				writes.push({ kind: 'type', id: body.type })
				if (typeLatency > 0) await sleep(typeLatency)
				if (typeOutcome) throw typeOutcome
			} else {
				throw new Error(`unexpected card PATCH body: ${config.data}`)
			}
		}
		return { status: 200, statusText: 'OK', data: { ok: true }, headers: {}, config }
	}

	const scope = effectScope()
	t.after(() => scope.stop())

	const { labels, assignees, priority, cardType } = app.runWithContext(() => scope.run(() => ({
		labels: useLabels(boardId),
		assignees: useAssignees(boardId),
		// These two bind their card at composable level, not per call - the modal
		// builds them for the card it has open.
		priority: usePriority(boardId, String(cardId)),
		cardType: useCardType(boardId, String(cardId)),
	})))

	return { queryClient, writes, labels, assignees, priority, cardType }
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

// #10927 - the priority and card-type pickers, which had the same rollback.

test('a FAILED priority write must not revert a card type set while it was in flight', async (t) => {
	const { queryClient, writes, priority, cardType } = harness(t, 205, 2006, {
		priorityLatency: 120,
		priorityOutcome: REFUSED,
	})

	// Set a priority...
	const prioritySet = priority.setPriority.mutateAsync({ priority: 4 })
	// ...and a type while that priority write is still on the wire. These two
	// share no queue at all, so nothing holds the second one back.
	await cardType.setType.mutateAsync({ type: 'bug' })

	// The write order is the onMutate order (the adapter records a write before
	// it sleeps), so this is the snapshot-before-the-competing-write check: the
	// priority snapshot was taken, then the type patch landed on top of it.
	assert.deepEqual(writes, [{ kind: 'priority', id: 4 }, { kind: 'type', id: 'bug' }],
		'both writes must have gone out, the type one during the priority one')
	assert.equal(detail(queryClient, 2006).type, 'bug',
		'the type write succeeded, so the client holds it')

	// Now the priority write is refused.
	await assert.rejects(() => prioritySet, 'the priority set must reject to its own caller')
	await sleep(20)

	assert.equal(detail(queryClient, 2006).type, 'bug',
		'the failed priority rollback must leave the committed type alone - the '
		+ 'server has it, so erasing it client-side makes the client lie')
	assert.equal(detail(queryClient, 2006).priority, 0,
		'while still rolling back the priority it owns')
	assert.equal(summary(queryClient, 205, 2006).type, 'bug',
		'same in the board summary cache, which the card tile renders from')
	assert.equal(summary(queryClient, 205, 2006).priority, 0)
})

test('and a FAILED card-type write must not revert a priority set while it was in flight', async (t) => {
	// The mirror direction - the rollback was over-broad in both composables, so
	// fixing one and inheriting the other by assumption is how half of this stays
	// broken.
	const { queryClient, writes, priority, cardType } = harness(t, 206, 2008, {
		typeLatency: 120,
		typeOutcome: REFUSED,
	})

	const typeSet = cardType.setType.mutateAsync({ type: 'chore' })
	await priority.setPriority.mutateAsync({ priority: 2 })

	assert.deepEqual(writes, [{ kind: 'type', id: 'chore' }, { kind: 'priority', id: 2 }])
	await assert.rejects(() => typeSet)
	await sleep(20)

	assert.equal(detail(queryClient, 2008).priority, 2,
		'the priority write succeeded, so the failed type rollback must not undo it')
	assert.equal(detail(queryClient, 2008).type, '',
		'while still rolling back the type it owns')
	assert.equal(summary(queryClient, 206, 2008).priority, 2)
	assert.equal(summary(queryClient, 206, 2008).type, '')
})

test('a FAILED priority write must not revert a label added while it was in flight', async (t) => {
	// Across the two families: priority is a scalar and labelIds an array, so a
	// whole-object restore here erased a list the user had just added to.
	const { queryClient, writes, priority, labels } = harness(t, 207, 2010, {
		priorityLatency: 120,
		priorityOutcome: REFUSED,
	})

	const prioritySet = priority.setPriority.mutateAsync({ priority: 3 })
	await labels.enqueueToggle({ cardId: 2010, labelId: 11, assign: true })

	assert.deepEqual(writes, [{ kind: 'priority', id: 3 }, { kind: 'label', id: 11 }])
	await assert.rejects(() => prioritySet)
	await sleep(20)

	assert.deepEqual(detail(queryClient, 2010).labelIds, [11],
		'the label write succeeded, so the failed priority rollback must not undo it')
	assert.equal(detail(queryClient, 2010).priority, 0)
	assert.deepEqual(summary(queryClient, 207, 2010).labelIds, [11])
	assert.equal(summary(queryClient, 207, 2010).priority, 0)
})

test('a failed priority or type write still rolls back its OWN field with nothing else in flight', async (t) => {
	// The narrowing must not become "no rollback at all": with no competing write
	// the optimistic value still has to come back off, and back to the value the
	// card already had rather than to the zero default.
	const { queryClient, priority, cardType } = harness(t, 208, 2012, {
		priorityLatency: 20,
		priorityOutcome: REFUSED,
		typeLatency: 20,
		typeOutcome: REFUSED,
	})

	queryClient.setQueryData(['card', '2012'], (old) => ({ ...old, priority: 1, type: 'task' }))
	queryClient.setQueryData(['board', '208'], (old) => ({
		...old,
		cards: old.cards.map((c) => (c.id === 2012 ? { ...c, priority: 1, type: 'task' } : c)),
	}))

	await assert.rejects(() => priority.setPriority.mutateAsync({ priority: 4 }))
	await assert.rejects(() => cardType.setType.mutateAsync({ type: 'bug' }))
	await sleep(20)

	assert.equal(detail(queryClient, 2012).priority, 1,
		'the refused priority must be off the card again, and the one it had kept')
	assert.equal(detail(queryClient, 2012).type, 'task',
		'and likewise the refused type')
	assert.equal(summary(queryClient, 208, 2012).priority, 1)
	assert.equal(summary(queryClient, 208, 2012).type, 'task')
})

test('a failed priority write does not revert the REST of the board cache either', async (t) => {
	// The board snapshot was the whole board object - every card in it.
	const { queryClient, priority } = harness(t, 209, 2014, {
		priorityLatency: 120,
		priorityOutcome: REFUSED,
	})

	const set = priority.setPriority.mutateAsync({ priority: 4 })
	// AFTER the snapshot, not with it: onMutate runs in a microtask, so writing
	// the cache in the same synchronous block as the set lands BEFORE the snapshot
	// and makes the assertion below vacuous (it passes that way even unfixed).
	await sleep(20)
	assert.equal(summary(queryClient, 209, 2014).priority, 4,
		'the optimistic patch must be in the cache, i.e. the snapshot is taken')
	// A delta arrives for a different card while the priority write is in flight.
	queryClient.setQueryData(['board', '209'], (old) => ({
		...old,
		cards: old.cards.map((c) => (c.id === 2015 ? { ...c, title: 'Renamed elsewhere' } : c)),
	}))

	await assert.rejects(() => set)
	await sleep(20)

	assert.equal(summary(queryClient, 209, 2015).title, 'Renamed elsewhere',
		'a refused priority on one card must not rewind another card in the board cache')
})

test('a failed card-type write does not revert the REST of the board cache either', async (t) => {
	const { queryClient, cardType } = harness(t, 210, 2016, {
		typeLatency: 120,
		typeOutcome: REFUSED,
	})

	const set = cardType.setType.mutateAsync({ type: 'feature' })
	await sleep(20)
	assert.equal(summary(queryClient, 210, 2016).type, 'feature',
		'the optimistic patch must be in the cache, i.e. the snapshot is taken')
	queryClient.setQueryData(['board', '210'], (old) => ({
		...old,
		cards: old.cards.map((c) => (c.id === 2017 ? { ...c, title: 'Renamed elsewhere' } : c)),
	}))

	await assert.rejects(() => set)
	await sleep(20)

	assert.equal(summary(queryClient, 210, 2017).title, 'Renamed elsewhere',
		'a refused card type on one card must not rewind another card in the board cache')
})

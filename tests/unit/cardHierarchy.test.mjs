// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = { _oc_webroot: '', location: { href: 'http://localhost/' }, addEventListener() {}, removeEventListener() {} }
const { createApp, effectScope, ref } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useCardHierarchy } = await import('../../src/composables/useCardHierarchy.js')
const { useCardActions } = await import('../../src/composables/useCardActions.js')
const { useBoard } = await import('../../src/composables/useBoard.js')
const { useCard } = await import('../../src/composables/useCard.js')
const { seedCursor, syncBoardDelta } = await import('../../src/composables/useBoardDelta.js')
globalThis.document = { hidden: false, visibilityState: 'visible', addEventListener() {}, removeEventListener() {} }

function deferred() {
	let resolve
	const promise = new Promise((done) => { resolve = done })
	return { promise, resolve }
}

function harness(t, initialParent = 10) {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity, staleTime: Infinity }, mutations: { retry: false, gcTime: 0 } } })
	const app = createApp({})
	app.use(VueQueryPlugin, { queryClient: client })
	const scope = effectScope()
	const within = (fn) => app.runWithContext(() => scope.run(fn))
	const boardId = ref('41')
	const records = new Map([
		[10, { id: 10, title: 'Parent A', parentCardId: null }],
		[20, { id: 20, title: 'Parent B', parentCardId: null }],
		[30, { id: 30, title: 'Parent C', parentCardId: null }],
		[101, { id: 101, title: 'Child', parentCardId: initialParent, doneAt: 1 }],
		[102, { id: 102, title: 'Sibling', parentCardId: 10, doneAt: 0 }],
	].map(([id, record]) => [id, { boardId: 41, stackId: 411, doneAt: 0, archived: false, priority: 0, ...record }]))
	let cursor = 20
	const changes = []
	const children = (id) => [...records.values()].filter((card) => !card.deletedAt && card.parentCardId === Number(id))
	const progress = (id) => ({ total: children(id).length, done: children(id).filter((card) => card.doneAt > 0).length })
	const detail = (id) => ({ ...records.get(Number(id)), description: 'Description', children: children(id).map((card) => ({ ...card })) })
	const board = (id = 41) => ({ board: { id: Number(id), title: 'Hierarchy' }, stacks: [], labels: [], cursor, etag: `${cursor}-15-internal`, cards: [...records.values()].filter((card) => !card.deletedAt && !card.isTemplate && card.boardId === Number(id)).map((card) => ({ ...card, childProgress: progress(card.id) })) })
	client.setQueryData(['board', '41'], board())
	client.setQueryData(['board', '42'], { board: { id: 42 }, cards: [] })
	for (const id of records.keys()) client.setQueryData(['card', String(id)], detail(id))
	const gates = []
	const pendingGates = []
	const requests = []
	let offline = false
	function hold(method, path, { fail = false, snapshot = false } = {}) {
		const started = deferred()
		const release = deferred()
		const gate = { method, path, fail, snapshot, started: started.promise, release: release.resolve, wait: release.promise, signal: started.resolve }
		gates.push(gate)
		pendingGates.push(gate)
		return gate
	}
	const original = axios.defaults.adapter
	axios.defaults.adapter = async (config) => {
		const path = config.url.slice(config.url.indexOf('/api/') + 4)
		const method = config.method.toUpperCase()
		const data = config.data ? JSON.parse(config.data) : undefined
		requests.push({ method, path, data, since: config.params?.since, validator: config.headers.get('If-None-Match') })
		if (method === 'GET' && offline) throw new Error('Offline')
		const match = /^\/cards\/(\d+)(\/parent)?$/.exec(path)
		const boardRead = /^\/boards\/\d+$/.test(path)
		const owner = boardRead ? Number(path.split('/').at(-1)) : 41
		const deltaRead = path === '/boards/41/changes'
		const payload = () => boardRead ? board(owner) : deltaRead ? { cursor, cards: { upsert: board().cards.filter((card) => changes.some((change) => change.cursor > config.params.since && change.id === card.id)), remove: [] }, stacks: { upsert: [], remove: [] } } : match ? detail(match[1]) : {}
		const snapshot = payload()
		const gateIndex = pendingGates.findIndex((gate) => gate.method === method && gate.path === path)
		const gate = gateIndex < 0 ? null : pendingGates.splice(gateIndex, 1)[0]
		if (gate) {
			gate.signal()
			await gate.wait
			if (gate.fail) throw Object.assign(new Error('HTTP 403'), { response: { status: 403, data: { error: 'Refused' }, headers: { 'content-type': 'application/json' } } })
		}
		let result
		if (method === 'GET') result = gate?.snapshot ? snapshot : payload()
		else if (method === 'PATCH' && match) {
			Object.assign(records.get(Number(match[1])), data)
			changes.push({ cursor: ++cursor, id: Number(match[1]) })
			result = detail(match[1])
		} else if (method === 'PUT' && match?.[2]) {
			records.get(Number(match[1])).parentCardId = data.parentCardId
			changes.push({ cursor: ++cursor, id: Number(match[1]) })
			result = detail(match[1])
		} else throw new Error(`Unexpected ${method} ${path}`)
		if (boardRead && config.headers.get('If-None-Match') === `"${cursor}-15-internal"`) return { config, status: 304, data: '', headers: {} }
		return { config, status: 200, data: result, headers: { 'content-type': 'application/json' } }
	}
	t.after(() => {
		for (const gate of gates) gate.release()
		scope.stop()
		client.unmount()
		client.clear()
		axios.defaults.adapter = original
	})
	return {
		client, within, boardId, records, board, detail, hold, requests,
		actions: within(() => useCardHierarchy(boardId)),
		cached: (id) => client.getQueryData(['card', String(id)]),
		summary: (id) => client.getQueryData(['board', '41']).cards.find((card) => card.id === id),
		remote(id, patch) { Object.assign(records.get(id), patch); changes.push({ cursor: ++cursor, id }) },
		offline() { offline = true },
	}
}

for (const target of [20, null]) {
	test(`a failed hierarchy change to ${target} preserves unrelated detail, board and sibling edits`, async (t) => {
		const h = harness(t)
		const gate = h.hold('PUT', '/cards/101/parent', { fail: true })
		const failure = assert.rejects(h.actions.setParent(101, target, 10), /403/)
		await gate.started
		assert.equal(h.summary(101).parentCardId, target)
		for (const id of [10, 20, 101, 102]) h.client.setQueryData(['card', String(id)], (old) => ({ ...old, title: `Edited ${id}`, priority: 3 }))
		h.client.setQueryData(['card', '10'], (old) => ({ ...old, children: [...old.children, { id: 103, title: 'New sibling', doneAt: 0 }] }))
		h.client.setQueryData(['board', '41'], (old) => ({ ...old, newerMetadata: true, cards: old.cards.map((card) => ({ ...card, title: `Edited ${card.id}`, priority: 3 })) }))
		gate.release()
		await failure
		assert.equal(h.summary(101).parentCardId, 10)
		for (const id of [10, 20, 101, 102]) {
			assert.equal(h.cached(id).title, `Edited ${id}`)
			assert.equal(h.summary(id).priority, 3)
		}
		assert.equal(h.client.getQueryData(['board', '41']).newerMetadata, true)
		assert.deepEqual(h.cached(10).children.map((card) => card.id).sort(), [101, 102, 103])
	})
}

test('a failed child move preserves another child joining the same parent', async (t) => {
	const h = harness(t)
	const gate = h.hold('PUT', '/cards/101/parent', { fail: true })
	const failure = assert.rejects(h.actions.setParent(101, 20, 10), /403/)
	await gate.started
	const other = h.within(() => useCardHierarchy(h.boardId))
	await other.setParent(102, 20, 10)
	gate.release()
	await failure
	assert.equal(h.summary(101).parentCardId, 10)
	assert.equal(h.summary(102).parentCardId, 20)
	assert.deepEqual(h.summary(10).childProgress, { total: 1, done: 1 })
	assert.deepEqual(h.summary(20).childProgress, { total: 1, done: 0 })
	assert.deepEqual(h.cached(20).children.map((card) => card.id), [102])
})

for (const firstFails of [false, true]) {
	for (const lastFails of [false, true]) {
		test(`same-child ABA actions all execute in order (first fails ${firstFails}, last fails ${lastFails})`, async (t) => {
			const h = harness(t)
			const first = h.hold('PUT', '/cards/101/parent', { fail: firstFails })
			const last = h.hold('PUT', '/cards/101/parent', { fail: lastFails })
			const a = h.actions.setParent(101, 20, 10)
			const aResult = firstFails ? assert.rejects(a, /403/) : a
			await first.started
			const other = h.within(() => useCardHierarchy(h.boardId))
			const b = other.setParent(101, 10, 20)
			const bResult = lastFails ? assert.rejects(b, /403/) : b
			await new Promise((done) => setImmediate(done))
			assert.equal(h.summary(101).parentCardId, 10)
			assert.equal(h.requests.filter((request) => request.method === 'PUT').length, 1)
			const samples = []
			const stop = h.client.getQueryCache().subscribe(() => samples.push(h.summary(101).parentCardId))
			first.release()
			await aResult
			await last.started
			assert.ok(samples.every((id) => id === 10), JSON.stringify(samples))
			last.release()
			await bResult
			stop()
			const expected = lastFails && !firstFails ? 20 : 10
			assert.deepEqual(h.requests.filter((request) => request.method === 'PUT').map((request) => request.data.parentCardId), [20, 10])
			assert.equal(h.records.get(101).parentCardId, expected)
			assert.equal(h.summary(101).parentCardId, expected)
			assert.equal(h.cached(101).parentCardId, expected)
			assert.deepEqual(h.summary(expected).childProgress, { total: expected === 10 ? 2 : 1, done: 1 })
		})
	}
}

test('a failed clear returns to the latest successful parent rather than an earlier optimistic parent', async (t) => {
	const h = harness(t)
	const first = h.hold('PUT', '/cards/101/parent', { fail: true })
	const firstResult = assert.rejects(h.actions.setParent(101, 20, 10), /403/)
	await first.started
	const middleGate = h.hold('PUT', '/cards/101/parent')
	const middle = h.actions.setParent(101, 30, 20)
	const last = h.hold('PUT', '/cards/101/parent', { fail: true })
	const lastResult = assert.rejects(h.actions.clearParent(101, 30), /403/)
	await new Promise((done) => setImmediate(done))
	assert.equal(h.summary(101).parentCardId, null)
	first.release()
	await firstResult
	await middleGate.started
	middleGate.release()
	await last.started
	last.release()
	await Promise.all([middle, lastResult])
	assert.equal(h.summary(101).parentCardId, 30)
	assert.deepEqual(h.requests.filter((request) => request.method === 'PUT').map((request) => request.data.parentCardId), [20, 30, null])
})

test('targets and initiating board are frozen before the mutation microtask and cancellation', async (t) => {
	const h = harness(t)
	const cancelling = deferred()
	const release = deferred()
	const cancel = h.client.cancelQueries.bind(h.client)
	h.client.cancelQueries = async (...args) => { cancelling.resolve(); await release.promise; return cancel(...args) }
	const action = h.actions.setParent(101, 20, 10)
	h.boardId.value = '42'
	await cancelling.promise
	release.resolve()
	await action
	assert.deepEqual(h.requests.filter((request) => request.path.startsWith('/boards/')).map((request) => request.path), ['/boards/41'])
	assert.equal(h.summary(101).parentCardId, 20)
	assert.equal(h.client.getQueryData(['board', '42']).board.id, 42)
})

test('a normal full and detail refetch during a pending move preserves optimistic membership and counts', async (t) => {
	const h = harness(t)
	h.within(() => useBoard(h.boardId))
	h.within(() => useCard(ref('101'), true))
	const gate = h.hold('PUT', '/cards/101/parent')
	const action = h.actions.setParent(101, 20, 10)
	await gate.started
	await h.client.refetchQueries({ queryKey: ['board', '41'] })
	await h.client.refetchQueries({ queryKey: ['card', '101'] })
	assert.equal(h.summary(101).parentCardId, 20)
	assert.equal(h.cached(101).parentCardId, 20)
	assert.deepEqual(h.summary(20).childProgress, { total: 1, done: 1 })
	gate.release()
	await action
})

test('a pending refetch cannot use an optimistic 304 as the failure baseline', async (t) => {
	const h = harness(t)
	h.within(() => useBoard(h.boardId))
	const gate = h.hold('PUT', '/cards/101/parent', { fail: true })
	const result = assert.rejects(h.actions.setParent(101, 20, 10), /403/)
	await gate.started
	await h.client.refetchQueries({ queryKey: ['board', '41'] })
	h.offline()
	gate.release()
	await result
	assert.equal(h.summary(101).parentCardId, 10)
	assert.equal(h.cached(101).parentCardId, 10)
})

test('a newer remote ABA observation survives an older drain snapshot and failed recovery', async (t) => {
	const h = harness(t)
	seedCursor(41, 20)
	const write = h.hold('PUT', '/cards/101/parent', { fail: true })
	const read = h.hold('GET', '/boards/41', { snapshot: true })
	const result = assert.rejects(h.actions.setParent(101, 20, 10), /403/)
	await write.started
	write.release()
	await read.started
	for (const parentCardId of [30, 10, 30]) {
		h.remote(101, { parentCardId })
		await syncBoardDelta(h.client, 41)
	}
	const samples = []
	const stop = h.client.getQueryCache().subscribe(() => samples.push(h.summary(101).parentCardId))
	read.release()
	await result
	stop()
	assert.equal(h.summary(101).parentCardId, 30)
	assert.equal(h.cached(101).parentCardId, 30)
	assert.ok(samples.every((id) => id === 30), JSON.stringify(samples))
})

test('a late delta cannot skip a newer remote move by advancing its cursor', async (t) => {
	const h = harness(t)
	seedCursor(41, 20)
	const old = h.hold('GET', '/boards/41/changes')
	const reading = syncBoardDelta(h.client, 41)
	await old.started
	await h.actions.setParent(101, 20, 10)
	h.remote(101, { parentCardId: 30 })
	old.release()
	await reading
	assert.equal(h.summary(101).parentCardId, 20)
	await syncBoardDelta(h.client, 41)
	assert.deepEqual(h.requests.filter((request) => request.path.endsWith('/changes')).map((request) => request.since), [20, 20])
	assert.equal(h.summary(101).parentCardId, 30)
})

test('template children absent from the board retain their contribution to parent progress', async (t) => {
	const h = harness(t)
	h.client.setQueryData(['board', '41'], (old) => ({ ...old, cards: old.cards.map((card) => card.id === 10 ? { ...card, childProgress: { total: 4, done: 2 } } : card) }))
	const write = h.hold('PUT', '/cards/101/parent', { fail: true })
	const result = assert.rejects(h.actions.clearParent(101, 10), /403/)
	await write.started
	assert.deepEqual(h.summary(10).childProgress, { total: 3, done: 1 })
	h.offline()
	write.release()
	await result
	assert.deepEqual(h.summary(10).childProgress, { total: 4, done: 2 })
})

test('repeating the current parent does not duplicate children or increment progress', async (t) => {
	const h = harness(t)
	const write = h.hold('PUT', '/cards/101/parent')
	const result = h.actions.setParent(101, 10, 20)
	await write.started
	assert.deepEqual(h.summary(10).childProgress, { total: 2, done: 1 })
	assert.deepEqual(h.cached(10).children.map((card) => card.id).sort(), [101, 102])
	write.release()
	await result
})

test('simultaneous lifecycle and hierarchy refetches never expose an older membership', async (t) => {
	const h = harness(t)
	h.within(() => useCard(ref('101'), true))
	const lifecycle = h.within(() => useCardActions(h.boardId, ref('101')))
	const hierarchyGate = h.hold('PUT', '/cards/101/parent')
	const lifecycleGate = h.hold('PATCH', '/cards/101')
	const changingParent = h.actions.setParent(101, 20, 10)
	const archiving = lifecycle.setArchived.mutateAsync({ archived: true })
	await Promise.all([hierarchyGate.started, lifecycleGate.started])
	const samples = []
	const stop = h.client.getQueryCache().subscribe(() => samples.push([h.summary(101).parentCardId, h.summary(101).archived, h.cached(101).parentCardId]))
	await h.client.refetchQueries({ queryKey: ['card', '101'] })
	stop()
	assert.ok(samples.length > 0)
	assert.ok(samples.every(([parent, archived, detailParent]) => parent === 20 && archived && detailParent === 20), JSON.stringify(samples))
	hierarchyGate.release()
	lifecycleGate.release()
	await Promise.all([changingParent, archiving])
	assert.equal(h.summary(101).parentCardId, 20)
	assert.equal(h.summary(101).archived, true)
})

test('a remote removal does not reinsert the absent child in its old parent detail', async (t) => {
	const h = harness(t)
	const gate = h.hold('PUT', '/cards/101/parent', { fail: true })
	const result = assert.rejects(h.actions.setParent(101, 20, 10), /403/)
	await gate.started
	h.remote(101, { deletedAt: 1 })
	gate.release()
	await result
	assert.equal(h.cached(10).children.some((card) => card.id === 101), false)
	assert.equal(h.cached(20).children.some((card) => card.id === 101), false)
	assert.deepEqual(h.summary(10).childProgress, { total: 1, done: 0 })
})

test('an origin failure cannot overwrite a newer parent on the child destination board', async (t) => {
	const h = harness(t)
	h.within(() => useCard(ref('101'), true))
	const first = h.hold('PUT', '/cards/101/parent', { fail: true })
	const failure = assert.rejects(h.actions.setParent(101, 20, 10), /403/)
	await first.started
	h.records.set(201, { id: 201, boardId: 42, stackId: 421, title: 'Destination parent', parentCardId: null })
	h.remote(101, { boardId: 42, stackId: 421, parentCardId: null })
	await h.client.refetchQueries({ queryKey: ['card', '101'] })
	h.client.setQueryData(['board', '42'], h.board(42))
	h.boardId.value = '42'
	const second = h.hold('PUT', '/cards/101/parent')
	const moving = h.actions.setParent(101, 201, null)
	await new Promise((done) => setImmediate(done))
	assert.equal(h.requests.filter((request) => request.method === 'PUT').length, 1)
	assert.equal(h.cached(101).parentCardId, 201)
	const samples = []
	const stop = h.client.getQueryCache().subscribe(() => samples.push(h.cached(101).parentCardId))
	first.release()
	await failure
	await second.started
	assert.ok(samples.every((parent) => parent === 201), JSON.stringify(samples))
	second.release()
	await moving
	stop()
	assert.equal(h.cached(101).parentCardId, 201)
	assert.equal(h.client.getQueryData(['board', '42']).cards.find((card) => card.id === 101).parentCardId, 201)
})

test('a template child can detach with correct progress and releases recovered hierarchy tracking', async (t) => {
	const h = harness(t)
	h.records.get(101).isTemplate = true
	h.client.setQueryData(['card', '101'], h.detail(101))
	h.client.setQueryData(['board', '41'], h.board())
	const write = h.hold('PUT', '/cards/101/parent')
	const result = h.actions.clearParent(101, 10)
	await write.started
	assert.deepEqual(h.summary(10).childProgress, { total: 1, done: 0 })
	write.release()
	await result
	assert.equal(h.cached(101).parentCardId, null)
	assert.deepEqual(h.summary(10).childProgress, { total: 1, done: 0 })
	h.within(() => useBoard(h.boardId))
	await h.client.refetchQueries({ queryKey: ['board', '41'] })
	assert.equal(h.requests.filter((request) => request.path === '/boards/41').at(-1).validator, '"20-15-internal"')
})

test('two template children transfer their residual contribution once and recover independently', async (t) => {
	const h = harness(t)
	for (const id of [101, 102]) {
		h.records.get(id).isTemplate = true
		h.client.setQueryData(['card', String(id)], h.detail(id))
	}
	h.client.setQueryData(['board', '41'], h.board())
	const first = h.hold('PUT', '/cards/101/parent', { fail: true })
	const second = h.hold('PUT', '/cards/102/parent')
	const firstResult = assert.rejects(h.actions.clearParent(101, 10), /403/)
	await first.started
	const secondResult = h.actions.clearParent(102, 10)
	await second.started
	assert.deepEqual(h.summary(10).childProgress, { total: 0, done: 0 })
	first.release()
	await firstResult
	assert.deepEqual(h.summary(10).childProgress, { total: 1, done: 1 })
	second.release()
	await secondResult
	assert.deepEqual(h.summary(10).childProgress, { total: 1, done: 1 })
	h.within(() => useBoard(h.boardId))
	await h.client.refetchQueries({ queryKey: ['board', '41'] })
	assert.equal(h.requests.filter((request) => request.path === '/boards/41').at(-1).validator, '"20-15-internal"')
})

test('a cancelled older detail response cannot overwrite a later remote move after recovery', async (t) => {
	const h = harness(t)
	h.within(() => useCard(ref('101'), true))
	seedCursor(41, 20)
	const old = h.hold('GET', '/cards/101', { snapshot: true })
	const reading = h.client.refetchQueries({ queryKey: ['card', '101'] })
	await old.started
	await h.actions.setParent(101, 20, 10)
	h.remote(101, { parentCardId: 30 })
	await syncBoardDelta(h.client, 41)
	const samples = []
	const stop = h.client.getQueryCache().subscribe(() => samples.push([h.cached(101).parentCardId, h.summary(101).parentCardId]))
	old.release()
	await reading
	await new Promise((done) => setImmediate(done))
	stop()
	assert.equal(h.summary(101).parentCardId, 30)
	assert.ok(samples.every(([detail, board]) => detail === 30 && board === 30), JSON.stringify(samples))
})

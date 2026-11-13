// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}

const { createApp, effectScope, ref } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useCardActions } = await import('../../src/composables/useCardActions.js')
const { useBoard } = await import('../../src/composables/useBoard.js')
const { useCard } = await import('../../src/composables/useCard.js')
const { seedCursor, syncBoardDelta } = await import('../../src/composables/useBoardDelta.js')

globalThis.document = {
	hidden: false,
	visibilityState: 'visible',
	addEventListener() {},
	removeEventListener() {},
}

function deferred() {
	let resolve
	const promise = new Promise((done) => { resolve = done })
	return { promise, resolve }
}

async function flush() {
	for (let i = 0; i < 8; i++) await new Promise((resolve) => setImmediate(resolve))
}

function harness(t) {
	const allGates = []
	const queryClient = new QueryClient({
		defaultOptions: {
			queries: { retry: false, gcTime: Infinity, staleTime: Infinity },
			mutations: { retry: false, gcTime: 0 },
		},
	})
	const app = createApp({})
	app.use(VueQueryPlugin, { queryClient })
	const scope = effectScope()
	t.after(() => {
		for (const gate of allGates) gate.release()
		scope.stop()
		queryClient.unmount()
		queryClient.clear()
	})
	const within = (fn) => app.runWithContext(() => scope.run(fn))
	const boardId = ref('41')
	const cardId = ref('101')
	const cards = new Map([
		[101, { id: 101, boardId: 41, stackId: 411, sortKey: 'a', title: 'Origin', archived: false, priority: 0, description: 'Detail' }],
		[102, { id: 102, boardId: 41, stackId: 411, sortKey: 'b', title: 'Neighbour', archived: false, priority: 0 }],
		[201, { id: 201, boardId: 42, stackId: 421, sortKey: 'a', title: 'Destination', archived: false, priority: 0 }],
	])
	let cursor = 20
	const history = [...cards.values()].map((card) => ({ cursor, id: card.id, boards: [card.boardId] }))
	const summary = (card) => {
		const { description, ...rest } = card
		return rest
	}
	const board = (id) => ({
		board: { id: Number(id), title: `Board ${id}` },
		cards: [...cards.values()].filter((card) => card.boardId === Number(id) && !card.deletedAt).map(summary),
		stacks: [{ id: Number(id) * 10 + 1, title: 'To do' }],
		labels: [], cursor, etag: `${cursor}-15-internal`,
	})
	const delta = (id, since) => {
		const changed = new Set(history.filter((change) => change.cursor > since && change.boards.includes(Number(id))).map((change) => change.id))
		return {
			cursor,
			cards: {
				upsert: board(id).cards.filter((card) => changed.has(card.id)),
				remove: [...changed].filter((cardId) => cards.get(cardId)?.deletedAt || cards.get(cardId)?.boardId !== Number(id)),
			},
			stacks: { upsert: [], remove: [] },
		}
	}
	queryClient.setQueryData(['board', '41'], board(41))
	queryClient.setQueryData(['board', '42'], board(42))
	queryClient.setQueryData(['card', '101'], { ...cards.get(101) })
	queryClient.setQueryData(['card', '201'], { ...cards.get(201) })
	const requests = []
	const holds = []
	function hold(method, path, { fail = false, snapshot = false } = {}) {
		const started = deferred()
		const released = deferred()
		const gate = { method, path, fail, snapshot, started: started.promise, release: released.resolve, wait: released.promise, signal: started.resolve }
		holds.push(gate)
		allGates.push(gate)
		return gate
	}
	function response(config, data, status = 200) {
		const result = { config, data, status, statusText: status === 200 ? 'OK' : 'Error', headers: { 'content-type': 'application/json' } }
		if (!config.validateStatus || config.validateStatus(status)) return result
		throw Object.assign(new Error(`HTTP ${status}`), { response: result })
	}
	axios.defaults.adapter = async (config) => {
		const path = config.url.slice(config.url.indexOf('/api/') + 4)
		const method = config.method.toUpperCase()
		requests.push({ method, path, headers: config.headers, since: config.params?.since })
		const gateIndex = holds.findIndex((gate) => gate.method === method && gate.path === path)
		const gate = gateIndex < 0 ? null : holds.splice(gateIndex, 1)[0]
		const cardMatch = /^\/cards\/(\d+)(\/restore)?$/.exec(path)
		const boardMatch = /^\/boards\/(\d+)$/.exec(path)
		const changesMatch = /^\/boards\/(\d+)\/changes$/.exec(path)
		const card = cardMatch ? cards.get(Number(cardMatch[1])) : null
		let data = boardMatch ? board(boardMatch[1]) : card ? { ...card } : {}
		if (changesMatch) {
			data = delta(changesMatch[1], config.params?.since ?? 0)
		}
		if (gate) {
			gate.signal()
			await gate.wait
			if (gate.fail) return response(config, { error: 'Refused' }, 403)
		}
		if (method === 'GET') {
			if (cardMatch && (!card || card.deletedAt)) return response(config, { error: 'Not found' }, 404)
			if (!gate?.snapshot) data = boardMatch ? board(boardMatch[1]) : card ? { ...card }
				: changesMatch ? delta(changesMatch[1], config.params?.since ?? 0) : data
			const validator = config.headers?.get?.('If-None-Match')
			if (boardMatch && validator === `"${cursor}-15-internal"`) return response(config, '', 304)
			return response(config, data)
		}
		if (cardMatch) {
			if (method === 'PATCH') Object.assign(card, JSON.parse(config.data))
			if (method === 'DELETE') card.deletedAt = 1
			if (method === 'POST' && cardMatch[2]) card.deletedAt = 0
			cursor++
			history.push({ cursor, id: card.id, boards: [card.boardId] })
			return response(config, { ...card })
		}
		throw new Error(`Unexpected ${method} ${path}`)
	}
	const actions = within(() => useCardActions(boardId, cardId))
	const cachedBoard = (id = 41) => queryClient.getQueryData(['board', String(id)])
	const cachedCard = (id = 101) => queryClient.getQueryData(['card', String(id)])
	const cachedSummary = (id = 101, owner = 41) => cachedBoard(owner)?.cards.find((card) => card.id === id)
	return {
		queryClient, within, boardId, cardId, cards, actions, requests, hold, board,
		cachedBoard, cachedCard, cachedSummary,
		remote: (id, patch) => {
			const before = cards.get(id).boardId
			Object.assign(cards.get(id), patch)
			cursor++
			history.push({ cursor, id, boards: [before, cards.get(id).boardId] })
		},
	}
}

test('a rejected archive preserves both boards and newer unrelated fields after navigation', async (t) => {
	const h = harness(t)
	const gate = h.hold('PATCH', '/cards/101', { fail: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await gate.started
	assert.equal(h.cachedSummary().archived, true)
	h.queryClient.setQueryData(['board', '41'], (old) => ({
		...old, extra: 'new metadata', cards: old.cards.map((card) => ({ ...card, title: `${card.title} newer`, priority: 3 })),
	}))
	h.queryClient.setQueryData(['card', '101'], (old) => ({ ...old, title: 'Origin newer', priority: 3 }))
	h.boardId.value = '42'
	h.cardId.value = '201'
	h.queryClient.setQueryData(['board', '42'], (old) => ({ ...old, extra: 'Destination newer' }))
	const emissions = []
	const stop = h.queryClient.getQueryCache().subscribe(() => {
		emissions.push([h.cachedBoard(42)?.board.id, h.cachedBoard(42)?.extra, h.cachedSummary(102)?.title])
	})
	gate.release()
	await failed
	await flush()
	stop()
	assert.ok(emissions.length > 0)
	assert.ok(emissions.every(([id, extra, title]) => id === 42 && extra === 'Destination newer' && title === 'Neighbour newer'), JSON.stringify(emissions))
	assert.equal(h.cachedSummary().archived, false)
	assert.equal(h.cachedSummary().title, 'Origin newer')
	assert.equal(h.cachedSummary().priority, 3)
	assert.equal(h.cachedCard().title, 'Origin newer')
	assert.equal(h.cachedCard().archived, false)
	assert.equal(h.cachedBoard().extra, 'new metadata')
})

test('the request target is captured before asynchronous cancellation', async (t) => {
	const h = harness(t)
	const paused = deferred()
	const started = deferred()
	const original = h.queryClient.cancelQueries.bind(h.queryClient)
	let first = true
	h.queryClient.cancelQueries = async (...args) => {
		if (first) { first = false; started.resolve(); await paused.promise }
		return original(...args)
	}
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	await started.promise
	h.boardId.value = '42'
	h.cardId.value = '201'
	paused.resolve()
	await action
	assert.deepEqual(h.requests.filter((request) => request.method === 'PATCH').map((request) => request.path), ['/cards/101'])
	assert.equal(h.cards.get(201).archived, false)
	assert.equal(h.cachedSummary().archived, true)
})

test('lifecycle requests from separate card views are FIFO and retain the latest optimistic intent', async (t) => {
	const h = harness(t)
	const secondView = h.within(() => useCardActions(41, 101))
	const first = h.hold('PATCH', '/cards/101', { fail: true })
	const firstAction = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(firstAction, /403/)
	await first.started
	const second = h.hold('PATCH', '/cards/101')
	const secondAction = secondView.setArchived.mutateAsync({ archived: false })
	await flush()
	assert.equal(h.requests.filter((request) => request.method === 'PATCH').length, 1)
	assert.equal(h.cachedSummary().archived, false)
	const observed = []
	const stop = h.queryClient.getQueryCache().subscribe(() => observed.push(h.cachedSummary()?.archived))
	first.release()
	await second.started
	await failed
	assert.equal(h.cachedSummary().archived, false)
	second.release()
	await secondAction
	stop()
	assert.ok(observed.every((archived) => archived === false), JSON.stringify(observed))
	assert.equal(h.cards.get(101).archived, false)
})

test('a failed delete restores only its target and preserves a newer detail edit', async (t) => {
	const h = harness(t)
	const gate = h.hold('DELETE', '/cards/101', { fail: true })
	const action = h.actions.deleteCard.mutateAsync()
	const failed = assert.rejects(action, /403/)
	await gate.started
	assert.equal(h.cachedSummary(), undefined)
	h.queryClient.setQueryData(['card', '101'], (old) => ({ ...old, title: 'Edited while deleting', priority: 4 }))
	h.queryClient.setQueryData(['board', '41'], (old) => ({ ...old, cards: [...old.cards, { id: 103, title: 'New card', archived: false }] }))
	gate.release()
	await failed
	assert.equal(h.cachedSummary().title, 'Edited while deleting')
	assert.equal(h.cachedSummary().priority, 4)
	assert.equal(h.cachedSummary(103).title, 'New card')
})

test('an unconditional read reconciles a failed archive even when the unchanged board ETag would return 304', async (t) => {
	const h = harness(t)
	h.within(() => useBoard(h.boardId))
	const gate = h.hold('PATCH', '/cards/101', { fail: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await gate.started
	gate.release()
	await failed
	await flush()
	assert.equal(h.cachedSummary().archived, false)
	assert.ok(h.requests.some((request) => request.method === 'GET' && request.path === '/cards/101'))
})

test('a failed delete does not reinsert a card moved to another board remotely', async (t) => {
	const h = harness(t)
	const gate = h.hold('DELETE', '/cards/101', { fail: true })
	const action = h.actions.deleteCard.mutateAsync()
	const failed = assert.rejects(action, /403/)
	await gate.started
	h.remote(101, { boardId: 42, stackId: 421, sortKey: 'c' })
	gate.release()
	await failed
	assert.equal(h.cachedSummary(), undefined)
	assert.equal(h.cachedSummary(101, 42)?.stackId, 421)
	assert.equal(h.cachedBoard(42).cards.find((card) => card.id === 201).title, 'Destination')
})

test('a delayed pre-archive board read cannot resurrect a successful archive', async (t) => {
	const h = harness(t)
	h.within(() => useBoard(h.boardId))
	const read = h.hold('GET', '/boards/41', { snapshot: true })
	const refetch = h.queryClient.refetchQueries({ queryKey: ['board', '41'] })
	await read.started
	await h.actions.setArchived.mutateAsync({ archived: true })
	const observed = []
	const stop = h.queryClient.getQueryCache().subscribe(() => observed.push(h.cachedSummary()?.archived))
	read.release()
	await refetch
	await flush()
	stop()
	assert.ok(observed.every((archived) => archived === true), JSON.stringify(observed))
	assert.equal(h.cachedSummary().archived, true)
})

test('a delayed pre-delete delta cannot resurrect a successfully deleted card', async (t) => {
	const h = harness(t)
	seedCursor(41, 19)
	const delta = h.hold('GET', '/boards/41/changes', { snapshot: true })
	const syncing = syncBoardDelta(h.queryClient, 41)
	await delta.started
	await h.actions.deleteCard.mutateAsync()
	const observed = []
	const stop = h.queryClient.getQueryCache().subscribe(() => observed.push(h.cachedSummary()))
	delta.release()
	await syncing
	await flush()
	stop()
	assert.ok(observed.every((card) => card === undefined), JSON.stringify(observed))
	assert.equal(h.cachedSummary(), undefined)
})

test('a confirmed deletion stays hidden when authoritative refresh fails', async (t) => {
	const h = harness(t)
	const read = h.hold('GET', '/cards/101', { fail: true })
	const action = h.actions.deleteCard.mutateAsync()
	await read.started
	assert.equal(h.cachedSummary(), undefined)
	read.release()
	const receipt = await action
	assert.equal(receipt.current(), true)
	assert.equal(h.cachedSummary(), undefined)
})

test('a rejected archive falls back without losing unrelated edits when authoritative refresh fails', async (t) => {
	const h = harness(t)
	const write = h.hold('PATCH', '/cards/101', { fail: true })
	const read = h.hold('GET', '/cards/101', { fail: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await write.started
	h.queryClient.setQueryData(['board', '41'], (old) => ({ ...old, cards: old.cards.map((card) => ({ ...card, priority: 4 })) }))
	write.release()
	await read.started
	read.release()
	await failed
	assert.equal(h.cachedSummary().archived, false)
	assert.equal(h.cachedSummary().priority, 4)
})

test('Undo uses the completed operation target after navigating to another card', async (t) => {
	const h = harness(t)
	const receipt = await h.actions.deleteCard.mutateAsync()
	h.boardId.value = '42'
	h.cardId.value = '201'
	await h.actions.restoreCard.mutateAsync({ target: receipt.target, undoOf: receipt.operationId })
	assert.equal(h.cards.get(101).deletedAt, 0)
	assert.equal(h.cards.get(201).deletedAt, undefined)
	assert.equal(h.cachedSummary()?.id, 101)
	assert.deepEqual(h.requests.filter((request) => request.method === 'POST').map((request) => request.path), ['/cards/101/restore'])
})

test('an older Undo cannot supersede a newer lifecycle gesture on the same card', async (t) => {
	const h = harness(t)
	const old = await h.actions.setArchived.mutateAsync({ archived: true })
	await h.actions.setArchived.mutateAsync({ archived: false })
	await h.actions.setArchived.mutateAsync({ archived: true })
	const count = h.requests.length
	assert.equal(old.current(), false)
	const stale = await h.actions.setArchived.mutateAsync({ target: old.target, undoOf: old.operationId, archived: false })
	assert.equal(stale, null)
	assert.equal(h.requests.length, count)
	assert.equal(h.cachedSummary().archived, true)
})

test('remote lifecycle observations supersede an older drain read, including an observed ABA transition', async (t) => {
	const h = harness(t)
	seedCursor(41, 20)
	const write = h.hold('PATCH', '/cards/101', { fail: true })
	const read = h.hold('GET', '/boards/41', { snapshot: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await write.started
	write.release()
	await read.started
	for (const archived of [true, false, true]) {
		h.remote(101, { archived })
		await syncBoardDelta(h.queryClient, 41)
	}
	const observed = []
	const stop = h.queryClient.getQueryCache().subscribe(() => observed.push(h.cachedSummary()?.archived))
	read.release()
	await failed
	await flush()
	stop()
	assert.equal(h.cachedSummary().archived, true)
	assert.ok(observed.every((archived) => archived === true), JSON.stringify(observed))
})

test('a normal card refetch during a pending archive preserves its optimistic state', async (t) => {
	const h = harness(t)
	h.within(() => useCard(h.cardId, true))
	const write = h.hold('PATCH', '/cards/101')
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	await write.started
	await h.queryClient.refetchQueries({ queryKey: ['card', '101'] })
	assert.equal(h.cachedCard().archived, true)
	write.release()
	await action
	assert.equal(h.cachedCard().archived, true)
})

test('an unrelated board read cannot retire a deletion whose authoritative refresh failed', async (t) => {
	const h = harness(t)
	seedCursor(41, 19)
	const delta = h.hold('GET', '/boards/41/changes', { snapshot: true })
	const syncing = syncBoardDelta(h.queryClient, 41)
	await delta.started
	const detailRead = h.hold('GET', '/cards/101', { fail: true })
	const boardRead = h.hold('GET', '/boards/41', { fail: true })
	const action = h.actions.deleteCard.mutateAsync()
	await detailRead.started
	detailRead.release()
	await boardRead.started
	boardRead.release()
	await action
	h.within(() => useBoard(ref('42')))
	await h.queryClient.refetchQueries({ queryKey: ['board', '42'] })
	const observed = []
	const stop = h.queryClient.getQueryCache().subscribe(() => observed.push(h.cachedSummary()))
	delta.release()
	await syncing
	stop()
	assert.ok(observed.every((card) => card === undefined), JSON.stringify(observed))
	assert.equal(h.cachedSummary(), undefined)
})

test('an HTML 404 during refresh does not erase an observed readable card', async (t) => {
	const h = harness(t)
	const original = axios.defaults.adapter
	axios.defaults.adapter = async (config) => {
		if (config.method === 'get' && /\/cards\/101$/.test(config.url)) {
			throw Object.assign(new Error('Proxy not found'), { response: { status: 404, data: '<html>Not Found</html>', headers: { 'content-type': 'text/html' } } })
		}
		return original(config)
	}
	await h.actions.setArchived.mutateAsync({ archived: true })
	assert.equal(h.cachedSummary().archived, true)
	assert.equal(h.cachedCard().archived, true)
})

test('a pending board refetch cannot promote an optimistic 304 payload to the failure baseline', async (t) => {
	const h = harness(t)
	h.within(() => useBoard(h.boardId))
	const write = h.hold('PATCH', '/cards/101', { fail: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await write.started
	await h.queryClient.refetchQueries({ queryKey: ['board', '41'] })
	assert.equal(h.cachedSummary().archived, true)
	const original = axios.defaults.adapter
	axios.defaults.adapter = (config) => {
		if (config.method === 'get') throw new Error('Offline during recovery')
		return original(config)
	}
	write.release()
	await failed
	await flush()
	assert.equal(h.cachedSummary().archived, false)
})

test('an older read during a pending action cannot replace a newer remote observation before failed recovery', async (t) => {
	const h = harness(t)
	h.within(() => useBoard(h.boardId))
	seedCursor(41, 20)
	const write = h.hold('PATCH', '/cards/101', { fail: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await write.started
	const old = h.hold('GET', '/boards/41', { snapshot: true })
	const reading = h.queryClient.refetchQueries({ queryKey: ['board', '41'] })
	await old.started
	h.remote(101, { archived: true })
	await syncBoardDelta(h.queryClient, 41)
	old.release()
	await reading
	const original = axios.defaults.adapter
	axios.defaults.adapter = (config) => {
		if (config.method === 'get') throw new Error('Offline during recovery')
		return original(config)
	}
	const observed = []
	const stop = h.queryClient.getQueryCache().subscribe(() => observed.push(h.cachedSummary()?.archived))
	write.release()
	await failed
	await flush()
	stop()
	assert.equal(h.cachedSummary().archived, true)
	assert.ok(observed.every((archived) => archived === true), JSON.stringify(observed))
})

test('a remote move observed through detail removes the old board membership before the action drains', async (t) => {
	const h = harness(t)
	h.within(() => useCard(h.cardId, true))
	const write = h.hold('PATCH', '/cards/101', { fail: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await write.started
	h.remote(101, { boardId: 42, stackId: 421, sortKey: 'c' })
	await h.queryClient.refetchQueries({ queryKey: ['card', '101'] })
	assert.equal(h.cachedSummary(), undefined)
	assert.equal(h.cachedSummary(101, 42)?.boardId, 42)
	const original = axios.defaults.adapter
	axios.defaults.adapter = (config) => {
		if (config.method === 'get') throw new Error('Offline during recovery')
		return original(config)
	}
	write.release()
	await failed
	assert.equal(h.cachedSummary(), undefined)
	assert.equal(h.cachedSummary(101, 42)?.archived, false)
	assert.equal(h.cachedCard()?.boardId, 42)
})

test('a remote lifecycle ABA transition permanently supersedes the older Undo', async (t) => {
	const h = harness(t)
	const receipt = await h.actions.setArchived.mutateAsync({ archived: true })
	h.within(() => useBoard(h.boardId))
	for (const archived of [false, true]) {
		h.remote(101, { archived })
		await h.queryClient.refetchQueries({ queryKey: ['board', '41'] })
	}
	assert.equal(receipt.current(), false)
	const count = h.requests.length
	await h.actions.setArchived.mutateAsync({ target: receipt.target, undoOf: receipt.operationId, archived: false })
	assert.equal(h.requests.length, count)
	assert.equal(h.cachedSummary().archived, true)
})

test('a fresh not-found response during a pending action survives failed recovery', async (t) => {
	const h = harness(t)
	h.within(() => useCard(h.cardId, true))
	const write = h.hold('PATCH', '/cards/101', { fail: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await write.started
	h.remote(101, { deletedAt: 1 })
	await h.queryClient.refetchQueries({ queryKey: ['card', '101'] })
	const original = axios.defaults.adapter
	axios.defaults.adapter = (config) => {
		if (config.method === 'get') throw new Error('Offline during recovery')
		return original(config)
	}
	write.release()
	await failed
	await flush()
	assert.equal(h.cachedSummary(), undefined)
	assert.equal(h.cachedCard(), undefined)
})

test('declining an older delta retains its cursor so a newer remote change is read again', async (t) => {
	const h = harness(t)
	seedCursor(41, 20)
	const old = h.hold('GET', '/boards/41/changes')
	const reading = syncBoardDelta(h.queryClient, 41)
	await old.started
	await h.actions.setArchived.mutateAsync({ archived: true })
	h.remote(101, { archived: false })
	old.release()
	await reading
	assert.equal(h.cachedSummary().archived, true)
	await syncBoardDelta(h.queryClient, 41)
	assert.deepEqual(h.requests.filter((request) => request.path.endsWith('/changes')).map((request) => request.since), [20, 20])
	assert.equal(h.cachedSummary().archived, false)
})

test('a declined late board read cannot seed a cursor past a remote lifecycle change', async (t) => {
	const h = harness(t)
	h.within(() => useBoard(h.boardId))
	seedCursor(41, 20)
	const old = h.hold('GET', '/boards/41')
	const reading = h.queryClient.refetchQueries({ queryKey: ['board', '41'] })
	await old.started
	await h.actions.setArchived.mutateAsync({ archived: true })
	h.remote(101, { archived: false })
	old.release()
	await reading
	await flush()
	await syncBoardDelta(h.queryClient, 41)
	assert.equal(h.cachedSummary().archived, false)
	assert.ok(h.requests.filter((request) => request.path.endsWith('/changes')).at(-1).since < 22)
})

test('authoritative absence clears the board target even if its board refresh fails', async (t) => {
	const h = harness(t)
	const write = h.hold('PATCH', '/cards/101', { fail: true })
	const boardRead = h.hold('GET', '/boards/41', { fail: true })
	const action = h.actions.setArchived.mutateAsync({ archived: true })
	const failed = assert.rejects(action, /403/)
	await write.started
	h.remote(101, { deletedAt: 1 })
	write.release()
	await boardRead.started
	boardRead.release()
	await failed
	assert.equal(h.cachedSummary(), undefined)
	assert.equal(h.cachedCard(), undefined)
})

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = { _oc_webroot: '', location: { href: 'http://localhost/' }, addEventListener() {}, removeEventListener() {} }
const { createApp, effectScope, ref } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useLabels } = await import('../../src/composables/useLabels.js')
const { useAssignees } = await import('../../src/composables/useAssignees.js')
const { usePriority } = await import('../../src/composables/usePriority.js')
const { useCardType } = await import('../../src/composables/useCardType.js')
globalThis.document = { hidden: false, visibilityState: 'visible', addEventListener() {}, removeEventListener() {} }

function deferred() {
	let resolve
	const promise = new Promise((r) => { resolve = r })
	return { promise, resolve }
}

for (const scenario of [
	{ name: 'label', field: 'labelIds', before: [], after: [10], trigger: (c) => c.labels.enqueueToggle({ cardId: 1, labelId: 10, assign: true }) },
	{ name: 'assignee', field: 'assigneeIds', before: [], after: ['person'], trigger: (c) => c.assignees.enqueueToggle({ cardId: 1, userId: 'person', assign: true }) },
	{ name: 'priority', field: 'priority', before: 0, after: 3, trigger: (c) => c.priority.setPriority.mutateAsync({ priority: 3 }) },
	{ name: 'type', field: 'type', before: '', after: 'bug', trigger: (c) => c.type.setType.mutateAsync({ type: 'bug' }) },
]) {
	for (const success of [false, true]) {
		test(`${scenario.name} ${success ? 'success' : 'failure'} reconciles the original board after navigation`, async (t) => {
			const boardId = ref('1')
			const cardId = ref('1')
			const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false, gcTime: 0 } } })
			const app = createApp({})
			app.use(VueQueryPlugin, { queryClient })
			const scope = effectScope()
			t.after(() => { scope.stop(); queryClient.unmount(); queryClient.clear() })
			const cardA = { id: 1, labelIds: [], assigneeIds: [], priority: 0, type: '' }
			const cardB = { id: 2, labelIds: [99], assigneeIds: ['other'], priority: 1, type: 'task' }
			queryClient.setQueryData(['board', '1'], { cards: [cardA] })
			queryClient.setQueryData(['board', '2'], { cards: [cardB] })
			queryClient.setQueryData(['card', '1'], cardA)
			queryClient.setQueryData(['card', '2'], cardB)
			const arrived = deferred()
			const release = deferred()
			const oldAdapter = axios.defaults.adapter
			t.after(() => { axios.defaults.adapter = oldAdapter })
			axios.defaults.adapter = async (config) => {
				if (config.url.includes('/participants')) return { status: 200, statusText: 'OK', data: { items: [] }, headers: {}, config }
				arrived.resolve()
				await release.promise
				if (!success) throw { response: { status: 403 } }
				return { status: 200, statusText: 'OK', data: {}, headers: {}, config }
			}
			const composables = app.runWithContext(() => scope.run(() => ({
				labels: useLabels(boardId), assignees: useAssignees(boardId),
				priority: usePriority(boardId, cardId), type: useCardType(boardId, cardId),
			})))
			const pending = scenario.trigger(composables).then(() => null, (err) => err)
			await arrived.promise
			assert.deepEqual(queryClient.getQueryData(['board', '1']).cards[0][scenario.field], scenario.after)
			boardId.value = '2'
			cardId.value = '2'
			release.resolve()
			const result = await pending
			assert.equal(Boolean(result), !success)
			assert.deepEqual(queryClient.getQueryData(['board', '1']).cards[0][scenario.field], success ? scenario.after : scenario.before)
			assert.deepEqual(queryClient.getQueryData(['card', '1'])[scenario.field], success ? scenario.after : scenario.before)
			assert.deepEqual(queryClient.getQueryData(['board', '2']).cards[0], cardB)
			assert.deepEqual(queryClient.getQueryData(['card', '2']), cardB)
			assert.equal(queryClient.getQueryState(['board', '1']).isInvalidated, true)
			assert.equal(queryClient.getQueryState(['card', '1']).isInvalidated, true)
			assert.equal(queryClient.getQueryState(['board', '2']).isInvalidated, false)
			assert.equal(queryClient.getQueryState(['card', '2']).isInvalidated, false)
		})
	}
}

for (const queued of [
	{ name: 'label', field: 'labelIds', first: 10, second: 11, create: useLabels, args: (id) => ({ cardId: 1, labelId: id, assign: true }) },
	{ name: 'assignee', field: 'assigneeIds', first: 'alice', second: 'bob', create: useAssignees, args: (id) => ({ cardId: 1, userId: id, assign: true }) },
]) test(`queued ${queued.name} picks retain their original board after navigation`, async (t) => {
	const boardId = ref('1')
	const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false, gcTime: 0 } } })
	const app = createApp({})
	app.use(VueQueryPlugin, { queryClient })
	const scope = effectScope()
	t.after(() => { scope.stop(); queryClient.unmount(); queryClient.clear() })
	queryClient.setQueryData(['board', '1'], { cards: [{ id: 1, [queued.field]: [] }] })
	queryClient.setQueryData(['board', '2'], { cards: [{ id: 2, [queued.field]: [] }] })
	queryClient.setQueryData(['card', '1'], { id: 1, [queued.field]: [] })
	const arrived = deferred()
	const release = deferred()
	const oldAdapter = axios.defaults.adapter
	t.after(() => { axios.defaults.adapter = oldAdapter })
	let calls = 0
	axios.defaults.adapter = async (config) => {
		if (config.url.includes('/participants')) return { status: 200, statusText: 'OK', data: { items: [] }, headers: {}, config }
		calls++
		if (calls === 1) { arrived.resolve(); await release.promise; throw { response: { status: 403 } } }
		return { status: 200, statusText: 'OK', data: {}, headers: {}, config }
	}
	const picker = app.runWithContext(() => scope.run(() => queued.create(boardId)))
	const first = picker.enqueueToggle(queued.args(queued.first)).catch(() => {})
	await arrived.promise
	const second = picker.enqueueToggle(queued.args(queued.second))
	boardId.value = '2'
	release.resolve()
	await Promise.all([first, second])
	assert.deepEqual(queryClient.getQueryData(['board', '1']).cards[0][queued.field], [queued.second])
	assert.deepEqual(queryClient.getQueryData(['board', '2']).cards[0][queued.field], [])
})

for (const scenario of [
	{ name: 'priority', trigger: (c) => c.setPriority.mutateAsync({ priority: 3 }), create: usePriority, field: 'priority', expected: 3 },
	{ name: 'type', trigger: (c) => c.setType.mutateAsync({ type: 'bug' }), create: useCardType, field: 'type', expected: 'bug' },
]) {
	test(`${scenario.name} targets the original card when navigation interrupts cancellation`, async (t) => {
		const boardId = ref('1')
		const cardId = ref('1')
		const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false, gcTime: 0 } } })
		const app = createApp({})
		app.use(VueQueryPlugin, { queryClient })
		const scope = effectScope()
		t.after(() => { scope.stop(); queryClient.unmount(); queryClient.clear() })
		const a = { id: 1, priority: 0, type: '' }
		const b = { id: 2, priority: 0, type: '' }
		queryClient.setQueryData(['board', '1'], { cards: [a] })
		queryClient.setQueryData(['board', '2'], { cards: [b] })
		queryClient.setQueryData(['card', '1'], a)
		queryClient.setQueryData(['card', '2'], b)
		const cancelStarted = deferred()
		const releaseCancel = deferred()
		const originalCancel = queryClient.cancelQueries.bind(queryClient)
		queryClient.cancelQueries = async (...args) => {
			if (args[0]?.queryKey?.[0] === 'board') { cancelStarted.resolve(); await releaseCancel.promise }
			return originalCancel(...args)
		}
		const oldAdapter = axios.defaults.adapter
		t.after(() => { axios.defaults.adapter = oldAdapter })
		let requestUrl
		axios.defaults.adapter = async (config) => {
			requestUrl = config.url
			return { status: 200, statusText: 'OK', data: {}, headers: {}, config }
		}
		const composable = app.runWithContext(() => scope.run(() => scenario.create(boardId, cardId)))
		const pending = scenario.trigger(composable)
		await cancelStarted.promise
		boardId.value = '2'
		cardId.value = '2'
		releaseCancel.resolve()
		await pending
		assert.match(requestUrl, /\/cards\/1$/)
		assert.equal(queryClient.getQueryData(['board', '1']).cards[0][scenario.field], scenario.expected)
		assert.deepEqual(queryClient.getQueryData(['board', '2']).cards[0], b)
	})
}

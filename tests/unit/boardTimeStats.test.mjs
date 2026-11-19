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
const { useBoardTimeStats } = await import('../../src/composables/useBoardTimeStats.js')
globalThis.document = { hidden: false, visibilityState: 'visible', addEventListener() {}, removeEventListener() {} }

function harness(t, adapter, initiallyEnabled = true) {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
	const app = createApp({})
	app.use(VueQueryPlugin, { queryClient: client })
	const scope = effectScope()
	const boardId = ref(7)
	const range = ref({ from: '2026-11-01', to: '2026-11-18' })
	const enabled = ref(initiallyEnabled)
	axios.defaults.adapter = adapter
	const stats = app.runWithContext(() => scope.run(() => useBoardTimeStats(boardId, range, enabled)))
	t.after(() => { scope.stop(); client.unmount(); client.clear() })
	return { stats, client, boardId, range, enabled }
}

function reply(config, totalSeconds) {
	return { status: 200, statusText: 'OK', config, headers: {}, data: { totalSeconds, byUser: [] } }
}

async function flush() {
	for (let i = 0; i < 10; i++) await new Promise((resolve) => setImmediate(resolve))
}

test('disabled time tracking skips requests until the feature is enabled', async (t) => {
	const requests = []
	const h = harness(t, async (config) => {
		requests.push(config)
		return reply(config, 600)
	}, false)
	await flush()
	assert.equal(requests.length, 0)
	h.enabled.value = true
	await flush()
	assert.equal(requests.length, 1)
	assert.match(requests[0].url, /\/boards\/7\/time-stats$/)
	assert.deepEqual(requests[0].params, { from: '2026-11-01', to: '2026-11-18' })
	assert.equal(h.stats.data.value.totalSeconds, 600)
})

test('a previous date ranges delayed response cannot replace the selected ranges totals', async (t) => {
	let firstEntered
	const entered = new Promise((resolve) => { firstEntered = resolve })
	let releaseFirst
	const delayed = new Promise((resolve) => { releaseFirst = resolve })
	const requests = []
	const h = harness(t, async (config) => {
		requests.push(config)
		if (config.params.from === '2026-11-01') {
			firstEntered()
			await delayed
			return reply(config, 3600)
		}
		return reply(config, 600)
	})
	await entered
	h.range.value = { from: '2026-11-18', to: '2026-11-18' }
	await flush()
	assert.equal(h.stats.data.value.totalSeconds, 600)
	releaseFirst()
	await flush()
	assert.equal(h.stats.data.value.totalSeconds, 600)
	assert.equal(requests.length, 2)
	assert.deepEqual(requests.map((config) => config.params), [
		{ from: '2026-11-01', to: '2026-11-18' },
		{ from: '2026-11-18', to: '2026-11-18' },
	])
})

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = { _oc_webroot: '', location: { href: 'http://localhost/' }, addEventListener() {}, removeEventListener() {} }
const { effectScope, ref } = await import('vue')
const axios = (await import('@nextcloud/axios')).default
const { useResolvedCardReferences } = await import('../../src/composables/useResolvedCardReferences.js')

function harness(t, adapter, tokens = ['OLD-1']) {
	axios.defaults.adapter = adapter
	const scope = effectScope()
	const board = ref(7)
	const context = ref('viewer:7:source:allowed')
	const references = ref(tokens)
	const cached = ref(new Map())
	const resolved = scope.run(() => useResolvedCardReferences(board, context, references, cached))
	t.after(() => scope.stop())
	return { board, context, references, cached, resolved, scope }
}

const response = (config, cardId = 91, title = 'Moved destination') => ({ config, status: 200, statusText: 'OK', headers: {}, data: { cardId, title } })
async function flush() {
	for (let i = 0; i < 8; i++) await new Promise((resolve) => setImmediate(resolve))
}

test('local references skip reads and repeated missing tokens resolve once', async (t) => {
	const requests = []
	const h = harness(t, async (config) => { requests.push(config); return response(config) }, [])
	h.cached.value = new Map([['HERE-1', { cardId: 5, title: 'Local' }]])
	h.references.value = ['HERE-1', 'OLD-1', 'OLD-1']
	await flush()
	assert.equal(requests.length, 1)
	assert.match(requests[0].url, /boards\/7\/cards\/by-ref\/OLD-1$/)
	assert.equal(h.resolved.value.get('HERE-1').title, 'Local')
	assert.equal(h.resolved.value.get('OLD-1').title, 'Moved destination')
})

test('navigation and access context changes cannot reuse an earlier title', async (t) => {
	const pending = []
	const h = harness(t, (config) => new Promise((resolve) => pending.push({ config, resolve })))
	await flush()
	h.context.value = 'viewer:7:other-source:allowed'
	await flush()
	assert.equal(pending.length, 2)
	assert.equal(pending[0].config.signal.aborted, true)
	pending[1].resolve(response(pending[1].config, 92, 'Current destination'))
	await flush()
	pending[0].resolve(response(pending[0].config, 91, 'Stale private title'))
	await flush()
	assert.equal(h.resolved.value.get('OLD-1').title, 'Current destination')
	h.context.value = null
	assert.equal(h.resolved.value.size, 0)
	assert.equal(pending[1].config.signal.aborted, true)
})

test('missing, inaccessible and failed references stay unresolved without retrying', async (t) => {
	const requests = []
	const h = harness(t, async (config) => { requests.push(config); throw new Error('Not found') }, ['OLD-1', 'OLD-2'])
	await flush()
	assert.equal(h.resolved.value.size, 0)
	assert.equal(requests.length, 2)
})

test('lookups have a fixed request bound and bounded concurrent reads', async (t) => {
	const pending = []
	const h = harness(t, (config) => new Promise((resolve) => pending.push({ config, resolve })), Array.from({ length: 100 }, (_, index) => `OLD-${index + 1}`))
	await flush()
	assert.equal(pending.length, 4)
	let completed = 0
	while (completed < 50) {
		const batch = pending.slice(completed)
		assert.ok(batch.length > 0 && batch.length <= 4)
		for (const item of batch) item.resolve(response(item.config))
		completed += batch.length
		await flush()
	}
	assert.equal(pending.length, 50)
	assert.equal(h.resolved.value.size, 50)
	h.scope.stop()
	assert.ok(pending.every((item) => item.config.signal.aborted))
})

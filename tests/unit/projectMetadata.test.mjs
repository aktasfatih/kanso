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
const { createApp, effectScope } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useProjects } = await import('../../src/composables/useProjects.js')

globalThis.document = { visibilityState: 'visible' }

function deferred() {
	let resolve
	const promise = new Promise((done) => { resolve = done })
	return { promise, resolve }
}

function harness(t) {
	const initial = { id: 1, title: 'Launch', lead: 'alice', leadDisplayName: 'Alice' }
	const other = { id: 2, title: 'Other', lead: 'carol', leadDisplayName: 'Carol' }
	const client = new QueryClient({ defaultOptions: {
		queries: { retry: false, staleTime: Infinity, gcTime: 0 },
		mutations: { retry: false, gcTime: 0 },
	} })
	client.setQueryData(['projects'], [initial, other])
	const app = createApp({})
	app.use(VueQueryPlugin, { queryClient: client })
	const started = deferred()
	const outcome = deferred()
	axios.defaults.adapter = async (config) => {
		if (config.method !== 'patch') throw new Error('Project list unavailable')
		started.resolve()
		const result = await outcome.promise
		if (result instanceof Error) throw result
		return { status: 200, statusText: 'OK', data: result, headers: {}, config }
	}
	const scope = effectScope()
	const { update } = app.runWithContext(() => scope.run(() => useProjects()))
	t.after(() => {
		scope.stop()
		client.unmount()
		client.clear()
	})
	return { client, update, started: started.promise, finish: outcome.resolve, initial, other }
}

test('a changed lead never shows the previous lead name while the server is unavailable', async (t) => {
	const rig = harness(t)
	const write = rig.update.mutateAsync({ id: 1, lead: 'bob' })
	await rig.started
	assert.deepEqual(rig.client.getQueryData(['projects']), [
		{ ...rig.initial, lead: 'bob', leadDisplayName: null }, rig.other,
	])
	rig.finish({ ...rig.initial, lead: 'bob' })
	await write
	assert.equal(rig.client.getQueryData(['projects'])[0].leadDisplayName, null)
})

test('clearing a lead removes the previous name from the optimistic display', async (t) => {
	const rig = harness(t)
	const write = rig.update.mutateAsync({ id: 1, lead: '' })
	await rig.started
	assert.deepEqual(rig.client.getQueryData(['projects'])[0], { ...rig.initial, lead: '', leadDisplayName: null })
	rig.finish({ ...rig.initial, lead: null })
	await write
})

test('a null lead and an unrelated edit preserve the current lead name', async (t) => {
	const rig = harness(t)
	const write = rig.update.mutateAsync({ id: 1, title: 'Renamed', lead: null })
	await rig.started
	assert.deepEqual(rig.client.getQueryData(['projects'])[0], { ...rig.initial, title: 'Renamed' })
	rig.finish({ ...rig.initial, title: 'Renamed' })
	await write
})

test('a denied lead change restores the previous lead and display name', async (t) => {
	const rig = harness(t)
	const write = rig.update.mutateAsync({ id: 1, lead: 'bob' })
	await rig.started
	rig.finish(new Error('Permission denied'))
	await assert.rejects(write, /Permission denied/)
	assert.deepEqual(rig.client.getQueryData(['projects']), [rig.initial, rig.other])
})

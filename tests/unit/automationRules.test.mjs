// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { setImmediate } from 'node:timers/promises'

globalThis.window = {
	_oc_webroot: '',
	location: { href: 'http://localhost/' },
	addEventListener() {},
	removeEventListener() {},
}
const { createApp, effectScope, nextTick, ref } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useAutomationRules } = await import('../../src/composables/useAutomationRules.js')

test('automation reads follow management permission for the current board', async (t) => {
	const client = new QueryClient({ defaultOptions: {
		queries: { retry: false, staleTime: Infinity, gcTime: 0 },
	} })
	const app = createApp({})
	app.use(VueQueryPlugin, { queryClient: client })
	const requests = []
	const adapter = axios.defaults.adapter
	axios.defaults.adapter = async (config) => {
		requests.push(config.url)
		return { status: 200, statusText: 'OK', data: [{ id: requests.length }], headers: {}, config }
	}
	const scope = effectScope()
	const boardId = ref(1)
	const canManage = ref(false)
	const rules = app.runWithContext(() => scope.run(() => useAutomationRules(boardId, { enabled: canManage })))
	t.after(() => {
		scope.stop()
		client.unmount()
		client.clear()
		axios.defaults.adapter = adapter
	})
	const settle = async () => { await nextTick(); await setImmediate() }

	await settle()
	assert.deepEqual(requests, [])
	canManage.value = true
	await settle()
	assert.deepEqual(requests, ['/index.php/apps/kanso/api/boards/1/automation-rules'])
	assert.deepEqual(rules.data.value, [{ id: 1 }])

	canManage.value = false
	boardId.value = 2
	await settle()
	assert.equal(requests.length, 1)
	assert.equal(rules.data.value, undefined)
	canManage.value = true
	await settle()
	assert.deepEqual(requests, [
		'/index.php/apps/kanso/api/boards/1/automation-rules',
		'/index.php/apps/kanso/api/boards/2/automation-rules',
	])
	assert.deepEqual(rules.data.value, [{ id: 2 }])
})

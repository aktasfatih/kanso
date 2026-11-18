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
const { useCardTimeEntries } = await import('../../src/composables/useCardTimeEntries.js')
globalThis.document = { hidden: false, visibilityState: 'visible', addEventListener() {}, removeEventListener() {} }

function harness(t, adapter) {
	const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false, gcTime: 0 } } })
	const app = createApp({})
	app.use(VueQueryPlugin, { queryClient: client })
	const scope = effectScope()
	const openCard = ref('11')
	const invalidated = []
	client.invalidateQueries = async ({ queryKey }) => { invalidated.push(queryKey) }
	axios.defaults.adapter = adapter
	const entries = app.runWithContext(() => scope.run(() => useCardTimeEntries(openCard)))
	t.after(() => { scope.stop(); client.unmount(); client.clear() })
	return { ...entries, openCard, invalidated, client }
}

function reply(config, data) {
	return { status: 200, statusText: 'OK', data, headers: {}, config }
}

test('a timer write and its refreshes stay on the card and board where it was started', async (t) => {
	let began
	const entered = new Promise((resolve) => { began = resolve })
	let finish
	const delayed = new Promise((resolve) => { finish = resolve })
	const writes = []
	const setup = harness(t, async (config) => {
		if (config.method === 'post') {
			writes.push(config.url)
			began()
			await delayed
			return reply(config, { id: 1, cardId: 11, startedAt: 100 })
		}
		return reply(config, [])
	})
	const pending = setup.startTimer.mutateAsync({ cardId: '11', boardId: 3 })
	await entered
	setup.openCard.value = '22'
	finish()
	await pending
	assert.match(writes[0], /\/cards\/11\/timer$/)
	assert.deepEqual(setup.invalidated, [['card-time-entries', '11'], ['card', '11'], ['board', '3'], ['board-time-stats', '3']])
})

test('a refused delete restores its own entry list after changing cards', async (t) => {
	let began
	const entered = new Promise((resolve) => { began = resolve })
	let reject
	const delayed = new Promise((_resolve, refuse) => { reject = refuse })
	const setup = harness(t, async (config) => {
		if (config.method === 'delete') {
			began()
			return delayed
		}
		return reply(config, config.url.endsWith('/cards/22/time-entries') ? [{ id: 9 }] : [])
	})
	await setup.refetch()
	setup.client.setQueryData(['card-time-entries', '11'], [{ id: 1 }, { id: 2 }])
	setup.client.setQueryData(['card-time-entries', '22'], [{ id: 9 }])
	const pending = setup.removeEntry.mutateAsync({ cardId: '11', boardId: 3, entryId: 1 })
	const outcome = assert.rejects(pending, /denied/)
	await entered
	assert.deepEqual(setup.client.getQueryData(['card-time-entries', '11']), [{ id: 2 }])
	setup.openCard.value = '22'
	reject(new Error('denied'))
	await outcome
	assert.deepEqual(setup.client.getQueryData(['card-time-entries', '11']), [{ id: 1 }, { id: 2 }])
	assert.deepEqual(setup.client.getQueryData(['card-time-entries', '22']), [{ id: 9 }])
	assert.ok(setup.invalidated.some((key) => key.join('/') === 'board-time-stats/3'))
})

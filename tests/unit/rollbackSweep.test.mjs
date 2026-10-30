// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import test, { after } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = { _oc_webroot: '', location: { href: 'http://localhost/' }, addEventListener() {}, removeEventListener() {} }
const { createApp, effectScope } = await import('vue')
const { QueryClient, VueQueryPlugin } = await import('@tanstack/vue-query')
const axios = (await import('@nextcloud/axios')).default
const { useSubscription } = await import('../../src/composables/useSubscription.js')
const { useContacts } = await import('../../src/composables/useContacts.js')
const { useReminders } = await import('../../src/composables/useReminders.js')
globalThis.document = { hidden: false, visibilityState: 'visible', addEventListener() {}, removeEventListener() {}, getElementsByTagName() { return [{ getAttribute(name) { return name === 'data-user' ? 'me' : null } }] } }

const clients = []
after(() => clients.forEach((client) => { client.unmount(); client.clear() }))
const REFUSED = { response: { status: 403, data: { error: 'Nope.' } } }

function harness(t) {
	const app = createApp({})
	const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 100 }, mutations: { retry: false, gcTime: 0 } } })
	app.use(VueQueryPlugin, { queryClient: client })
	clients.push(client)
	const scope = effectScope()
	t.after(() => scope.stop())
	const card = { id: 11, title: 'original', contacts: [], myReminders: [], subscription: { subscribed: false, subscribers: [], count: 0 }, parentCardId: null }
	client.setQueryData(['card', '11'], card)
	client.setQueryData(['board', '1'], { cards: [{ ...card }, { id: 12, title: 'neighbour' }] })
	let release
	let notifyRequest
	const requested = new Promise((resolve) => { notifyRequest = resolve })
	const outcome = new Promise((resolve) => { release = resolve })
	axios.defaults.adapter = async (config) => {
		notifyRequest(config)
		await outcome
		throw REFUSED
	}
	const hooks = app.runWithContext(() => scope.run(() => ({
		subscription: useSubscription(11),
		contacts: useContacts(1),
		reminders: useReminders(),
	})))
	return { client, requested, release, ...hooks }
}

for (const method of ['toggle', 'toggleOther']) {
	test(`failed ${method} preserves concurrent watcher and card changes`, async (t) => {
		const { client, subscription, requested, release } = harness(t)
		const m = method === 'toggle' ? subscription.toggle : subscription.toggleOther
		const pending = m.mutateAsync(method === 'toggle' ? { subscribed: true } : { userId: 'alice', subscribed: true })
		await requested
		const ownId = method === 'toggle' ? 'me' : 'alice'
		assert.ok(client.getQueryData(['card', '11']).subscription.subscribers.includes(ownId))
		client.setQueryData(['card', '11'], (old) => ({ ...old, title: 'changed', subscription: { ...old.subscription, subscribers: [...old.subscription.subscribers, 'bob'], count: 2 } }))
		release()
		await assert.rejects(pending)
		const card = client.getQueryData(['card', '11'])
		assert.equal(card.title, 'changed')
		assert.deepEqual(card.subscription.subscribers, ['bob'])
		assert.equal(card.subscription.count, 1)
		assert.equal(card.subscription.subscribed, false)
	})
}

for (const link of [true, false]) {
	test(`failed contact ${link ? 'link' : 'unlink'} preserves other card and board changes`, async (t) => {
		const { client, contacts, requested, release } = harness(t)
		const contact = { contactUri: 'a', displayName: 'Alice' }
		if (!link) {
			client.setQueryData(['card', '11'], (old) => ({ ...old, contacts: [contact] }))
			client.setQueryData(['board', '1'], (old) => ({ ...old, cards: old.cards.map((c) => c.id === 11 ? { ...c, contacts: [contact] } : c) }))
		}
		const pending = contacts.toggleContact.mutateAsync({ cardId: 11, contact, link })
		await requested
		assert.equal(client.getQueryData(['card', '11']).contacts.length, link ? 1 : 0)
		client.setQueryData(['card', '11'], (old) => ({ ...old, title: 'changed' }))
		client.setQueryData(['board', '1'], (old) => ({ ...old, cards: old.cards.map((c) => c.id === 12 ? { ...c, title: 'changed' } : c) }))
		release()
		await assert.rejects(pending)
		assert.equal(client.getQueryData(['card', '11']).title, 'changed')
		assert.equal(client.getQueryData(['board', '1']).cards[1].title, 'changed')
		assert.equal(client.getQueryData(['card', '11']).contacts.length, link ? 0 : 1)
		assert.equal(client.getQueryData(['board', '1']).cards[0].contacts.length, link ? 0 : 1)
	})
}

for (const create of [true, false]) {
	test(`failed reminder ${create ? 'create' : 'cancel'} preserves other reminder and card changes`, async (t) => {
		const { client, reminders, requested, release } = harness(t)
		const saved = { id: 5, remindAt: 100 }
		if (!create) client.setQueryData(['card', '11'], (old) => ({ ...old, myReminders: [saved] }))
		const pending = create
			? reminders.createReminder.mutateAsync({ cardId: 11, remindAt: 200 })
			: reminders.cancelReminder.mutateAsync({ cardId: 11, reminderId: 5 })
		await requested
		assert.equal(client.getQueryData(['card', '11']).myReminders.length, create ? 1 : 0)
		client.setQueryData(['card', '11'], (old) => ({ ...old, title: 'changed', myReminders: [...old.myReminders, { id: 6, remindAt: 300 }] }))
		release()
		await assert.rejects(pending)
		const card = client.getQueryData(['card', '11'])
		assert.equal(card.title, 'changed')
		assert.deepEqual(card.myReminders.map((r) => r.id), create ? [6] : [5, 6])
	})
}


test('a failed reminder create removes only its own placeholder when identical creates overlap', async (t) => {
	const { client, reminders } = harness(t)
	let firstRelease
	let notifyFirst
	let notifySecond
	const firstRequested = new Promise((resolve) => { notifyFirst = resolve })
	const secondRequested = new Promise((resolve) => { notifySecond = resolve })
	const firstOutcome = new Promise((resolve) => { firstRelease = resolve })
	let calls = 0
	axios.defaults.adapter = async (config) => {
		calls++
		if (calls === 1) {
			notifyFirst()
			await firstOutcome
			throw REFUSED
		}
		notifySecond()
		return { status: 200, statusText: 'OK', data: { id: 99 }, headers: {}, config }
	}
	const first = reminders.createReminder.mutateAsync({ cardId: 11, remindAt: 200 })
	await firstRequested
	const second = reminders.createReminder.mutateAsync({ cardId: 11, remindAt: 200 })
	await secondRequested
	await second
	const before = client.getQueryData(['card', '11']).myReminders
	assert.equal(before.length, 2)
	assert.notEqual(before[0].id, before[1].id)
	assert.equal(before[1].id, 99)
	firstRelease()
	await assert.rejects(first)
	assert.deepEqual(client.getQueryData(['card', '11']).myReminders.map((r) => r.id), [before[1].id])
})


test('an unsaved optimistic reminder cannot be cancelled', async (t) => {
	const { client, reminders, requested, release } = harness(t)
	const pending = reminders.createReminder.mutateAsync({ cardId: 11, remindAt: 200 })
	await requested
	const optimisticId = client.getQueryData(['card', '11']).myReminders[0].id
	await assert.rejects(reminders.cancelReminder.mutateAsync({ cardId: 11, reminderId: optimisticId }), /not been saved/)
	assert.equal(client.getQueryData(['card', '11']).myReminders[0].id, optimisticId)
	release()
	await assert.rejects(pending)
	assert.deepEqual(client.getQueryData(['card', '11']).myReminders, [])
})

test('failed self-watch preserves a successful concurrent other-watcher mutation', async (t) => {
	const { client, subscription } = harness(t)
	let release
	let notify
	const started = new Promise((resolve) => { notify = resolve })
	const hold = new Promise((resolve) => { release = resolve })
	axios.defaults.adapter = async (config) => {
		if (config.url.endsWith('/subscription')) { notify(); await hold; throw REFUSED }
		return { status: 200, statusText: 'OK', data: {}, headers: {}, config }
	}
	const self = subscription.toggle.mutateAsync({ subscribed: true })
	await started
	await subscription.toggleOther.mutateAsync({ userId: 'bob', subscribed: true })
	assert.deepEqual(client.getQueryData(['card', '11']).subscription.subscribers, ['me', 'bob'])
	release()
	await assert.rejects(self)
	assert.deepEqual(client.getQueryData(['card', '11']).subscription, { subscribed: false, subscribers: ['bob'], count: 1 })
})

test('failed reminder create preserves a concurrent successful cancel', async (t) => {
	const { client, reminders } = harness(t)
	client.setQueryData(['card', '11'], (old) => ({ ...old, myReminders: [{ id: 5, remindAt: 100 }] }))
	let release
	let notify
	const started = new Promise((resolve) => { notify = resolve })
	const hold = new Promise((resolve) => { release = resolve })
	axios.defaults.adapter = async (config) => {
		if (config.method === 'post') { notify(); await hold; throw REFUSED }
		return { status: 200, statusText: 'OK', data: {}, headers: {}, config }
	}
	const create = reminders.createReminder.mutateAsync({ cardId: 11, remindAt: 200 })
	await started
	await reminders.cancelReminder.mutateAsync({ cardId: 11, reminderId: 5 })
	assert.equal(client.getQueryData(['card', '11']).myReminders.length, 1)
	release()
	await assert.rejects(create)
	assert.deepEqual(client.getQueryData(['card', '11']).myReminders, [])
})

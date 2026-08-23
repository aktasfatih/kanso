// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, makeApi, me, currentAuth } from './helpers.js'

// This spec's DELETE endpoints return a JSON body (subscription state) that the
// test asserts on, so it needs a client that parses DELETE responses rather than
// the shared `api` whose `.delete` always resolves null. Keep the local content
// handling (`204 → null`, else parse JSON); build the client from `currentAuth`
// at call time so it acts as the worker's user under isolation (not admin).
async function api(method, path, body) {
	const r = await makeApi(currentAuth).raw(method, path, body)
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' && r.status === 204 ? null : r.json()
}

// Board-watch endpoints (#3449): GET/PUT/DELETE /api/boards/{id}/subscription,
// and the board payload's `subscription` block. Driven through the public API.
test.describe('Board subscriptions', () => {
	let boardId = 0

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Board-Sub E2E' })
		boardId = board.id
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`).catch(() => {})
	})

	test('watch / unwatch round-trip reflects in the endpoint and board payload', async () => {
		// Initially not watching.
		let sub = await api('GET', `/boards/${boardId}/subscription`)
		expect(sub.subscribed).toBe(false)
		expect(sub.count).toBe(0)

		// Watch → subscribed, count 1, self in subscribers.
		sub = await api('PUT', `/boards/${boardId}/subscription`)
		expect(sub.subscribed).toBe(true)
		expect(sub.count).toBe(1)
		expect(sub.subscribers).toContain(me)

		// Idempotent: watching again stays count 1.
		sub = await api('PUT', `/boards/${boardId}/subscription`)
		expect(sub.count).toBe(1)

		// The board payload carries the same watch state.
		const payload = await api('GET', `/boards/${boardId}`)
		expect(payload.subscription.subscribed).toBe(true)
		expect(payload.subscription.count).toBe(1)

		// Unwatch → back to not subscribed, count 0.
		sub = await api('DELETE', `/boards/${boardId}/subscription`)
		expect(sub.subscribed).toBe(false)
		expect(sub.count).toBe(0)

		// Idempotent unwatch.
		sub = await api('DELETE', `/boards/${boardId}/subscription`)
		expect(sub.subscribed).toBe(false)
	})
})

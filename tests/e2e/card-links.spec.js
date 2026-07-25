// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

async function api(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const text = await r.text()
	return { ok: r.ok, status: r.status, body: text ? JSON.parse(text) : null }
}

const STATES = ['open', 'closed', 'merged', 'unknown']

// Card GitHub links (#3465): attach / list / delete via the public API. State
// polling is best-effort (may be `unknown` with no network), so we assert the
// state is one of the allowed values rather than a specific one.
test.describe('Card GitHub links', () => {
	let boardId = 0
	let stackId = 0
	let cardId = 0

	test.beforeAll(async () => {
		boardId = (await api('POST', '/boards', { title: 'Card-Links E2E' })).body.id
		stackId = (await api('POST', '/stacks', { boardId, title: 'Tasks' })).body.id
		cardId = (await api('POST', '/cards', { stackId, title: 'Wire up auth' })).body.id
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`)
	})

	test('attach, list, and remove a PR link', async () => {
		// Starts empty.
		let res = await api('GET', `/cards/${cardId}/links`)
		expect(res.ok).toBe(true)
		expect(res.body).toEqual([])

		// Attach a GitHub PR URL.
		res = await api('POST', `/cards/${cardId}/links`, { url: 'https://github.com/nextcloud/server/pull/1' })
		expect(res.ok).toBe(true)
		expect(res.body.kind).toBe('pr')
		expect(STATES).toContain(res.body.state)
		const linkId = res.body.id

		// It shows up in the list.
		res = await api('GET', `/cards/${cardId}/links`)
		expect(res.body).toHaveLength(1)
		expect(res.body[0].url).toBe('https://github.com/nextcloud/server/pull/1')

		// A non-GitHub URL is rejected.
		res = await api('POST', `/cards/${cardId}/links`, { url: 'https://evil.example.com/x/y/pull/1' })
		expect(res.ok).toBe(false)
		expect(res.status).toBe(400)

		// Remove the link.
		res = await api('DELETE', `/cards/${cardId}/links/${linkId}`)
		expect(res.ok).toBe(true)
		res = await api('GET', `/cards/${cardId}/links`)
		expect(res.body).toEqual([])
	})
})

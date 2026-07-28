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
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' ? null : r.json()
}

// Backend automation (#3450): completing/archiving the last open child auto-
// completes the parent. Driven entirely through the public API.
test.describe('Auto-complete parent when all children are done', () => {
	let boardId = 0
	let stackId = 0

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Parent-Done E2E' })
		boardId = board.id
		const stack = await api('POST', '/stacks', { boardId, title: 'Tasks' })
		stackId = stack.id
	})

	test.afterAll(async () => {
		if (boardId) await api('DELETE', `/boards/${boardId}`).catch(() => {})
	})

	async function setup() {
		const parent = await api('POST', '/cards', { stackId, title: 'Parent task' })
		const c1 = await api('POST', '/cards', { stackId, title: 'Child A' })
		const c2 = await api('POST', '/cards', { stackId, title: 'Child B' })
		await api('PUT', `/cards/${c1.id}/parent`, { parentCardId: parent.id })
		await api('PUT', `/cards/${c2.id}/parent`, { parentCardId: parent.id })
		return { parent, c1, c2 }
	}

	test('parent stays open until the last child is done, then auto-completes', async () => {
		const { parent, c1, c2 } = await setup()

		// Done one child - parent must stay open.
		await api('PATCH', `/cards/${c1.id}`, { done: true })
		let p = await api('GET', `/cards/${parent.id}`)
		expect(Number(p.doneAt)).toBe(0)

		// Done the last child - parent auto-completes.
		await api('PATCH', `/cards/${c2.id}`, { done: true })
		p = await api('GET', `/cards/${parent.id}`)
		expect(Number(p.doneAt)).toBeGreaterThan(0)
	})

	test('an archived child counts as resolved', async () => {
		const { parent, c1, c2 } = await setup()
		await api('PATCH', `/cards/${c1.id}`, { done: true })
		// Archive the other child instead of completing it.
		await api('PATCH', `/cards/${c2.id}`, { archived: true })
		const p = await api('GET', `/cards/${parent.id}`)
		expect(Number(p.doneAt)).toBeGreaterThan(0)
	})
})

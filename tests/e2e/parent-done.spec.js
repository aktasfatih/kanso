// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api } from './helpers.js'

// Backend automation (#3450): completing/archiving the last open child auto-
// completes the parent. Driven entirely through the public API.
test.describe('Auto-complete parent when all children are done', () => {
	let boardId = 0
	let stackId = 0

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Parent-Done E2E' })
		boardId = board.id
		const stack = await api.post('/stacks', { boardId, title: 'Tasks' })
		stackId = stack.id
	})

	test.afterAll(async () => {
		if (boardId) await api.delete(`/boards/${boardId}`).catch(() => {})
	})

	async function setup() {
		const parent = await api.post('/cards', { stackId, title: 'Parent task' })
		const c1 = await api.post('/cards', { stackId, title: 'Child A' })
		const c2 = await api.post('/cards', { stackId, title: 'Child B' })
		await api.put(`/cards/${c1.id}/parent`, { parentCardId: parent.id })
		await api.put(`/cards/${c2.id}/parent`, { parentCardId: parent.id })
		return { parent, c1, c2 }
	}

	test('parent stays open until the last child is done, then auto-completes', async () => {
		const { parent, c1, c2 } = await setup()

		// Done one child - parent must stay open.
		await api.patch(`/cards/${c1.id}`, { done: true })
		let p = await api.get(`/cards/${parent.id}`)
		expect(Number(p.doneAt)).toBe(0)

		// Done the last child - parent auto-completes.
		await api.patch(`/cards/${c2.id}`, { done: true })
		p = await api.get(`/cards/${parent.id}`)
		expect(Number(p.doneAt)).toBeGreaterThan(0)
	})

	test('an archived child counts as resolved', async () => {
		const { parent, c1, c2 } = await setup()
		await api.patch(`/cards/${c1.id}`, { done: true })
		// Archive the other child instead of completing it.
		await api.patch(`/cards/${c2.id}`, { archived: true })
		const p = await api.get(`/cards/${parent.id}`)
		expect(Number(p.doneAt)).toBeGreaterThan(0)
	})
})

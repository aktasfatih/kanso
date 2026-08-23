// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test.describe('My tasks (#3441)', () => {
	const state = { boardId: 0, stackId: 0, assignedCardId: 0, unassignedCardId: 0, title: '' }

	test.beforeAll(async () => {
		state.title = 'MyTask ' + Math.floor(Date.now() / 1000)
		const board = await api.post('/boards', { title: 'MyTasks ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		state.assignedCardId = (await api.post('/cards', { stackId: state.stackId, title: state.title })).id
		state.unassignedCardId = (await api.post('/cards', { stackId: state.stackId, title: 'Not mine ' + Math.floor(Date.now() / 1000) })).id
		// Assign only the first card to the current user.
		await api.put(`/cards/${state.assignedCardId}/assignees/${me}`)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('my-cards returns only cards assigned to me', async () => {
		const cards = await api.get('/my-cards')
		const ids = cards.map((c) => c.id)
		expect(ids).toContain(state.assignedCardId)
		expect(ids).not.toContain(state.unassignedCardId)
		const mine = cards.find((c) => c.id === state.assignedCardId)
		expect(mine.boardId).toBe(state.boardId)
		expect(mine.boardTitle).toBeTruthy()
	})

	test('a done card drops out of my tasks', async () => {
		const doneCardId = (await api.post('/cards', { stackId: state.stackId, title: 'Finish me ' + Math.floor(Date.now() / 1000) })).id
		await api.put(`/cards/${doneCardId}/assignees/${me}`)
		expect((await api.get('/my-cards')).map((c) => c.id)).toContain(doneCardId)

		await api.patch(`/cards/${doneCardId}`, { done: true })
		expect((await api.get('/my-cards')).map((c) => c.id)).not.toContain(doneCardId)
	})

	test('My tasks panel lists the card and deep-links to it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)

		const row = page.locator('.my-cards-view__row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 15_000 })

		await row.click()
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/${state.assignedCardId}`))
	})
})

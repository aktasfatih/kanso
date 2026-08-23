// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

// The cards in a stack, top-to-bottom, from the board summary payload.
async function stackOrder(boardId, stackId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.cards
		.filter((c) => c.stackId === stackId && !c.archived)
		.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
		.map((c) => c.title)
}

// #3516 — "Move to top" / "Move to bottom" from the card ⋯ menu.
test.describe('Move card to top / bottom (card ⋯ menu)', () => {
	const state = { boardId: 0, stackId: 0, midId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Move-Menu E2E' })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		// Created in order → A (top), B (middle), C (bottom).
		await api.post('/cards', { stackId: state.stackId, title: 'Card A' })
		const b = await api.post('/cards', { stackId: state.stackId, title: 'Card B' })
		await api.post('/cards', { stackId: state.stackId, title: 'Card C' })
		state.midId = b.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${b.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('the middle card moves to the top, then to the bottom', async ({ page }) => {
		expect(await stackOrder(state.boardId, state.stackId)).toEqual(['Card A', 'Card B', 'Card C'])

		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the ⋯ menu and click "Move to top".
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move to top' }).click()
		await expect
			.poll(() => stackOrder(state.boardId, state.stackId), { timeout: 8_000 })
			.toEqual(['Card B', 'Card A', 'Card C'])

		// Now move it to the bottom.
		await page.locator('.card-modal__actions-menu button').first().click()
		await page.getByRole('menuitem', { name: 'Move to bottom' }).click()
		await expect
			.poll(() => stackOrder(state.boardId, state.stackId), { timeout: 8_000 })
			.toEqual(['Card A', 'Card C', 'Card B'])
	})
})

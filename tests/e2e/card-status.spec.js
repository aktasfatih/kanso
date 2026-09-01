// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, BASE, api, ncLogin } from './helpers.js'

const ROLE_IN_PROGRESS = 3
const ROLE_DONE = 5

test.describe('Card status (#3481)', () => {
	const state = { boardId: 0, todoStackId: 0, progStackId: 0, doneStackId: 0, autoCardId: 0, manualCardId: 0, syncCardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Status ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		state.todoStackId = (await api.post('/stacks', { boardId: board.id, title: 'To do' })).id
		state.progStackId = (await api.post('/stacks', { boardId: board.id, title: 'Doing' })).id
		state.doneStackId = (await api.post('/stacks', { boardId: board.id, title: 'Done' })).id
		await api.patch(`/stacks/${state.progStackId}`, { role: ROLE_IN_PROGRESS })
		await api.patch(`/stacks/${state.doneStackId}`, { role: ROLE_DONE })
		state.autoCardId = (await api.post('/cards', { stackId: state.todoStackId, title: 'Auto-started card' })).id
		state.manualCardId = (await api.post('/cards', { stackId: state.todoStackId, title: 'Manual status card' })).id
		state.syncCardId = (await api.post('/cards', { stackId: state.todoStackId, title: 'Status sync card' })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('moving a card into an in-progress-role column auto-starts it', async () => {
		let card = await api.get(`/cards/${state.autoCardId}`)
		expect(Number(card.startedAt)).toBe(0)

		await api.post(`/cards/${state.autoCardId}/move`, { targetStackId: state.progStackId, afterCardId: null })

		card = await api.get(`/cards/${state.autoCardId}`)
		expect(Number(card.startedAt)).toBeGreaterThan(0)
		expect(Number(card.doneAt)).toBe(0)
	})

	test('status can be set from the card view and shows on the tile', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.manualCardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// Set "In progress" from the card's status control (breadcrumb chip → dropdown)
		// by picking the in-progress-role COLUMN. The popover now also lists the three
		// generic statuses, so the column section is addressed explicitly.
		await page.locator('.card-modal__status-chip--btn').click()
		await page.locator('.card-modal__status-wrap .card-modal__popover-opt--column', { hasText: 'In progress' }).click()
		await expect(page.locator('.card-modal__status-chip--in_progress')).toBeVisible({ timeout: 6_000 })

		// Close the modal → the board tile shows the In-progress chip.
		await page.keyboard.press('Escape')
		const tile = page.locator('.card-tile', { hasText: 'Manual status card' })
		await expect(tile.locator('.card-tile__inprogress')).toBeVisible({ timeout: 8_000 })
	})

	test('setting a card Done from the card view moves it into the Done-role column (#54)', async ({ page }) => {
		// Precondition: the card starts in the To-do column, not started.
		let card = await api.get(`/cards/${state.syncCardId}`)
		expect(card.stackId).toBe(state.todoStackId)
		expect(Number(card.doneAt)).toBe(0)

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.syncCardId}`)
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 15_000 })

		// Mark it Done from the card's status control, via the Done-role column.
		await page.locator('.card-modal__status-chip--btn').click()
		await page.locator('.card-modal__status-wrap .card-modal__popover-opt--column', { hasText: 'Done' }).click()
		await expect(page.locator('.card-modal__status-chip--done')).toBeVisible({ timeout: 6_000 })

		// The status change carries the card into the Done-role column (#54), and it
		// is stamped done - status and board position stay in sync.
		await expect.poll(
			async () => (await api.get(`/cards/${state.syncCardId}`)).stackId,
			{ timeout: 8_000 },
		).toBe(state.doneStackId)
		card = await api.get(`/cards/${state.syncCardId}`)
		expect(Number(card.doneAt)).toBeGreaterThan(0)
	})
})

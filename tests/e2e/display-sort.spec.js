// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Display sort (#3442)', () => {
	const state = { boardId: 0 }
	// Created in this order → manual (fractional) order is Charlie, Alpha, Bravo.
	const created = ['Charlie', 'Alpha', 'Bravo']

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Sort ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		for (const title of created) {
			await api.post('/cards', { stackId: stack.id, title })
		}
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('sorting by Title reorders the rows; Manual restores fractional order', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// View + Sort now live in one Display popover. Pick an option then Escape to
		// close, so the next open() is deterministic (radios don't close the menu).
		const pick = async (name) => {
			await page.locator('.board-view__display-menu button').first().click()
			// Let the teleported popover settle before clicking (a click landing mid
			// open/re-render can miss the freshly-mounted radio).
			await page.waitForTimeout(400)
			await page.locator('.action-radio__text', { hasText: new RegExp('^' + name + '$') }).click()
			await page.waitForTimeout(150)
			await page.keyboard.press('Escape')
			await page.waitForTimeout(200)
		}

		// Switch to List view for a deterministic row order.
		await pick('List')
		await expect(page.locator('.board-list-row').first()).toBeVisible({ timeout: 8_000 })

		const titles = () => page.locator('.board-list-row__title').allTextContents()

		// Manual order = creation (fractional) order.
		expect((await titles()).map((s) => s.trim())).toEqual(created)

		// Sort by Title → alphabetical.
		await pick('Title')
		await expect
			.poll(async () => (await titles()).map((s) => s.trim()))
			.toEqual(['Alpha', 'Bravo', 'Charlie'])

		// Back to Manual → fractional order restored (view-only sort, keys intact).
		await pick('Manual')
		await expect
			.poll(async () => (await titles()).map((s) => s.trim()))
			.toEqual(created)
	})
})

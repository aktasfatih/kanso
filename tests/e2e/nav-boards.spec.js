// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Boards in the left navigation', () => {
	const state = { boardId: 0, title: 'Nav Board ' + Math.floor(Date.now() / 1000) }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: state.title, color: '9b59b6' })
		state.boardId = board.id
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('the board is listed in the nav and navigates when clicked', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.app-navigation', { timeout: 15_000 })

		// The board appears as a nav entry under Boards.
		const item = page.locator('.app-navigation .app-navigation-entry-link', { hasText: state.title })
		await expect(item.first()).toBeVisible({ timeout: 10_000 })

		await item.first().click()
		await expect(page).toHaveURL(new RegExp(`#/board/${state.boardId}`), { timeout: 10_000 })
		await page.waitForSelector('.board-view__header', { timeout: 10_000 })
	})
})

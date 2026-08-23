// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Responsive board header', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Responsive ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'To do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('view / sort / group / density all live under one Display popover', async ({ page }) => {
		await page.setViewportSize({ width: 1500, height: 900 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// One Display control; the old standalone view/sort menus are gone.
		await expect(page.locator('.board-view__display-menu')).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.board-view__view-menu')).toHaveCount(0)
		await expect(page.locator('.board-view__sort-menu')).toHaveCount(0)

		// It holds View + Sort (+ Group + Density).
		await page.locator('.board-view__display-menu button').first().click()
		await expect(page.getByRole('menuitemradio', { name: 'Timeline' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('menuitemradio', { name: 'Manual', exact: true })).toBeVisible()
	})

	test('narrow: header stays compact and Display remains reachable; nothing off-screen', async ({ page }) => {
		// Phone-width → the NC sidebar collapses and the header is compact.
		await page.setViewportSize({ width: 480, height: 900 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Search, filter, Display and ⋯ all remain reachable in the bar.
		await expect(page.locator('.board-view__search')).toBeVisible()
		await expect(page.locator('.board-filter-bar__filter')).toBeVisible()
		await expect(page.getByRole('button', { name: 'More' })).toBeVisible()
		const display = page.locator('.board-view__display-menu button').first()
		await expect(display).toBeVisible()

		// Display still drives the view: switch to List from it.
		await display.click()
		await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
		await expect(page.locator('.board-list-table')).toBeVisible({ timeout: 8_000 })
	})
})

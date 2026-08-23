// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Board background (#3528): a curated preset gradient rendered behind the board
// view. Presets only (no free-form CSS). This drives the palette from the UI and
// asserts the chosen background actually applies to the board view.
test.describe('Board background preset (#3528)', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Background E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('picking a background preset applies it to the board view', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		const boardView = page.locator('.board-view')
		// No background to start with.
		await expect(boardView).not.toHaveClass(/board-view--has-background/)

		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		await expect(page.locator('#bs-pane-general')).toBeVisible({ timeout: 8_000 })

		// Pick the "ocean" preset from the curated palette.
		await page.locator('[data-test="board-bg-ocean"]').click()

		// The board view now carries the background class and the CSS variable.
		await expect(boardView).toHaveClass(/board-view--has-background/, { timeout: 8_000 })
		const bg = await boardView.evaluate((el) => getComputedStyle(el).getPropertyValue('--board-background'))
		expect(bg).toContain('linear-gradient')

		// It persists across a reload (server is the source of truth).
		await page.reload()
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page.locator('.board-view')).toHaveClass(/board-view--has-background/, { timeout: 8_000 })

		// Clearing it removes the background again.
		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		await expect(page.locator('#bs-pane-general')).toBeVisible({ timeout: 8_000 })
		await page.locator('[data-test="board-bg-none"]').click()
		await expect(page.locator('.board-view')).not.toHaveClass(/board-view--has-background/, { timeout: 8_000 })
	})
})

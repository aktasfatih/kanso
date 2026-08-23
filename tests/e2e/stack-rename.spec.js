// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Column rename', () => {
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Column Rename E2E' })
		state.boardId = board.id
		await api.post('/stacks', { boardId: board.id, title: 'Original Column' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('clicking a column title renames it inline', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 12_000 })

		await page.locator('.stack-column__title', { hasText: 'Original Column' }).click()

		const input = page.locator('.stack-column__title-input')
		await expect(input).toBeVisible({ timeout: 4_000 })
		await input.fill('Renamed Column')
		await input.press('Enter')

		await expect(page.locator('.stack-column__title', { hasText: 'Renamed Column' })).toBeVisible({ timeout: 6_000 })

		// Persisted: a reload still shows the new name.
		await page.reload()
		await page.waitForSelector('.stack-column__header', { timeout: 12_000 })
		await expect(page.locator('.stack-column__title', { hasText: 'Renamed Column' })).toBeVisible({ timeout: 8_000 })
	})

	test('Escape cancels the rename', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 12_000 })

		await page.locator('.stack-column__title', { hasText: 'Renamed Column' }).click()
		const input = page.locator('.stack-column__title-input')
		await expect(input).toBeVisible({ timeout: 4_000 })
		await input.fill('Should Not Stick')
		await input.press('Escape')

		await expect(page.locator('.stack-column__title', { hasText: 'Renamed Column' })).toBeVisible({ timeout: 6_000 })
		await expect(page.locator('.stack-column__title', { hasText: 'Should Not Stick' })).toHaveCount(0)
	})
})

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Visual proportions', () => {
	const state = { boardUrl: '' }

	test.beforeAll(async () => {
		for (const b of await api.get('/boards')) {
			if (b.title === 'Visual Test Board') await api.delete(`/boards/${b.id}`)
		}
		const board = await api.post('/boards', { title: 'Visual Test Board' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('label color-option swatches render as true circles (width === height)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()

		// Open the new-label color picker grid
		await page.getByRole('button', { name: /pick color for new label/i }).click()
		const option = page.locator('.label-settings__color-option').first()
		await expect(option).toBeVisible({ timeout: 5000 })

		// A circle must be square: rendered width and height match (within 1px).
		const dims = await option.evaluate((el) => {
			const r = el.getBoundingClientRect()
			const cs = getComputedStyle(el)
			return { w: r.width, h: r.height, br: cs.borderRadius, bs: cs.boxSizing, display: getComputedStyle(el.parentElement).display }
		})
		expect(Math.abs(dims.w - dims.h), `swatch not square: ${JSON.stringify(dims)}`).toBeLessThanOrEqual(1)
		expect(dims.w).toBeGreaterThan(0)
	})
})

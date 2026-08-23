// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #3467: the label-settings colour-pick swatch must be a perfect circle, not an
// oval - assert the rendered button is geometrically square.
test.describe('Colour-pick swatch shape', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: 'Swatch ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('the label colour swatch renders square (circle, not oval)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open board settings → Labels tab. Board settings now lives in the
		// consolidated ⋯ More overflow menu, so open that first.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Board settings' }).click()
		await page.waitForSelector('.label-settings__swatch', { timeout: 10_000 })

		const box = await page.locator('.label-settings__swatch').first().boundingBox()
		expect(box).toBeTruthy()
		// Square within a sub-pixel tolerance → a circle once border-radius:50% applies.
		expect(Math.abs(box.width - box.height)).toBeLessThanOrEqual(1)
	})
})

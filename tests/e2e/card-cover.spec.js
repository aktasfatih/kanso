// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

async function cardCoverColor(cardId) {
	const card = await api.send('GET', `/cards/${cardId}`)
	return card.coverColor
}

// #3549 — card cover colour: a solid-colour band on the card tile, set/cleared
// from the card modal.
test.describe('Card cover colour', () => {
	const suffix = Math.random().toString(36).slice(2, 8)
	const state = { boardId: 0, stackId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.send('POST', '/boards', { title: `Card-Cover E2E ${suffix}` })
		state.boardId = board.id
		state.stackId = (await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		const card = await api.send('POST', '/cards', { stackId: state.stackId, title: 'Cover Card' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('setting a cover colour from the modal shows the band on the tile, then clearing removes it', async ({ page }) => {
		expect(await cardCoverColor(state.cardId)).toBeFalsy()

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		const tile = page.locator('.card-tile').filter({ hasText: 'Cover Card' })
		await expect(tile).toBeVisible({ timeout: 5000 })
		// No cover band before a colour is set.
		await expect(tile.locator('.card-tile__cover')).toHaveCount(0)

		// Open the card modal.
		await tile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the Cover picker in the attribute bar.
		await page.getByRole('button', { name: /^Cover$/ }).click()
		const swatches = page.locator('.card-modal__cover-swatch')
		await expect(swatches.first()).toBeVisible({ timeout: 5000 })

		// Pick the first swatch (Red, e74c3c) and wait for the persisting PATCH.
		const [patchSet] = await Promise.all([
			page.waitForResponse(
				(r) => /\/api\/cards\/\d+/.test(r.url()) && r.request().method() === 'PATCH',
				{ timeout: 15_000 },
			),
			swatches.first().click(),
		])
		expect(patchSet.ok()).toBeTruthy()

		// Persisted as the bare-hex preset.
		await expect.poll(() => cardCoverColor(state.cardId), { timeout: 10_000 }).toBe('e74c3c')

		// Close the modal and assert the tile now shows the cover band.
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		const coverBand = tile.locator('.card-tile__cover')
		await expect(coverBand).toBeVisible({ timeout: 6_000 })

		// Reopen the modal and clear the cover.
		await tile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await page.getByRole('button', { name: /^Cover$/ }).click()
		const clearBtn = page.getByRole('button', { name: /^No cover$/ })
		await expect(clearBtn).toBeVisible({ timeout: 5000 })
		const [patchClear] = await Promise.all([
			page.waitForResponse(
				(r) => /\/api\/cards\/\d+/.test(r.url()) && r.request().method() === 'PATCH',
				{ timeout: 15_000 },
			),
			clearBtn.click(),
		])
		expect(patchClear.ok()).toBeTruthy()

		// Cover cleared server-side …
		await expect.poll(() => cardCoverColor(state.cardId), { timeout: 10_000 }).toBeFalsy()

		// … and the band is gone from the tile.
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})
		await expect(tile.locator('.card-tile__cover')).toHaveCount(0, { timeout: 6_000 })
	})
})

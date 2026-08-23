// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// Density now lives under the Display popover (radios), not a standalone toggle.
async function setDensity(page, label) {
	await page.locator('.board-view__display-menu button').first().click()
	await page.getByRole('menuitemradio', { name: label, exact: true }).click()
	await page.keyboard.press('Escape')
}

// ─────────────────────────────────────────────────────────────────────────────
// Compact density mode (#3415)
//
// A per-user, view-only toggle that tightens every card tile (smaller padding,
// single-line title, smaller chips) so more cards fit on screen. Persisted per
// board per user via localStorage (kanso.density.<id>). Threaded down to each
// CardTile (which gets the .card-tile--compact class) and to the per-column
// virtualizer, which must re-measure on the flip so a long, scrolled stack
// doesn't jump.
//
// Coverage:
//  1. Toggle → tiles become compact (class + measurably shorter height).
//  2. Persists across a reload.
//  3. On a long, SCROLLED stack the toggle keeps the virtualizer honest — tiles
//     render, are compact, and the scroll position stays put (no jump to top).
// ─────────────────────────────────────────────────────────────────────────────
test.describe('Compact density (#3415)', () => {
	const TOTAL_CARDS = 40
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Density E2E' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Dense Stack' })
		state.stackId = stack.id
		for (let i = 1; i <= TOTAL_CARDS; i++) {
			await api.post('/cards', { stackId: stack.id, title: `Density card ${i}` })
		}
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('toggle makes tiles compact + shorter, and persists across reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		const firstTile = page.locator('.card-tile').first()
		await expect(firstTile).toBeVisible({ timeout: 8_000 })

		// Comfortable (default): no compact class.
		await expect(firstTile).not.toHaveClass(/card-tile--compact/)
		const comfortableHeight = (await firstTile.boundingBox()).height

		// Flip to compact via Display → Density.
		await setDensity(page, 'Compact')

		// Tiles gain the compact class and become measurably shorter.
		await expect(firstTile).toHaveClass(/card-tile--compact/, { timeout: 6_000 })
		const compactHeight = (await firstTile.boundingBox()).height
		expect(compactHeight).toBeLessThan(comfortableHeight)

		// Persisted per user: reload → still compact.
		await page.reload()
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await expect(page.locator('.card-tile').first()).toHaveClass(/card-tile--compact/, { timeout: 8_000 })

		// Flip back to comfortable and confirm it also persists.
		await setDensity(page, 'Comfortable')
		await expect(page.locator('.card-tile').first()).not.toHaveClass(/card-tile--compact/, { timeout: 6_000 })
		await page.reload()
		await page.waitForSelector('.card-tile', { timeout: 15_000 })
		await expect(page.locator('.card-tile').first()).not.toHaveClass(/card-tile--compact/, { timeout: 8_000 })
	})

	test('toggling on a scrolled long stack does not jump or break virtualization', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		const cardList = page.locator('.stack-column__cards').first()
		await expect(cardList).toBeVisible({ timeout: 8_000 })

		// Scroll the column well down so we're inside the virtualized window
		// (not at the top). A stale estimate/size-cache on the flip would jump
		// the scroll back toward 0 or mis-measure rows.
		await cardList.evaluate((el) => { el.scrollTop = 600 })
		await page.waitForTimeout(300)
		const scrollBefore = await cardList.evaluate((el) => el.scrollTop)
		expect(scrollBefore).toBeGreaterThan(200)

		// Virtualization proof before the flip: fewer tiles in the DOM than total.
		const beforeCount = await page.locator('.card-tile-wrap').count()
		expect(beforeCount).toBeLessThan(TOTAL_CARDS)
		expect(beforeCount).toBeGreaterThan(0)

		// Flip to compact — the virtualizer must re-measure, not jump to the top.
		await setDensity(page, 'Compact')
		await expect(page.locator('.card-tile').first()).toHaveClass(/card-tile--compact/, { timeout: 6_000 })
		await page.waitForTimeout(400)

		// Still virtualized, tiles still rendered, and we did NOT jump back to the
		// top (a stale 90px slot cache would have collapsed scrollTop toward 0).
		const afterCount = await page.locator('.card-tile-wrap').count()
		expect(afterCount).toBeLessThan(TOTAL_CARDS)
		expect(afterCount).toBeGreaterThan(0)
		await expect(page.locator('.card-tile-wrap').first()).toBeVisible({ timeout: 6_000 })

		const scrollAfter = await cardList.evaluate((el) => el.scrollTop)
		// The compact re-measure shrinks total height, so scrollTop can settle a
		// bit lower, but it must stay in the same neighbourhood — not reset to 0.
		expect(scrollAfter).toBeGreaterThan(100)
	})
})

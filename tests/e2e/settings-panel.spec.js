// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Settings panel (right-docked drawer)', () => {
	const BOARD_TITLE = 'SettingsPanel E2E ' + Date.now()
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		// Clean up any stale boards with the same name, then create fresh fixtures
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title.startsWith('SettingsPanel E2E')) {
				await api.delete(`/boards/${b.id}`)
			}
		}
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		await api.post('/cards', { stackId: stack.id, title: 'First card' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('panel opens anchored to the right, board remains visible and interactive, Escape closes', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)

		// Wait for the board to fully render (card tile is visible)
		const card = page.locator('.card-tile', { hasText: 'First card' })
		await expect(card).toBeVisible({ timeout: 20_000 })

		// Open settings panel via the ⋯ More overflow menu → Board settings.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()

		// Panel must be visible
		const panel = page.locator('.bs-modal')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		// ── Positioning assertions ────────────────────────────────────────────
		const viewport = page.viewportSize()
		const panelBox = await panel.boundingBox()
		expect(panelBox).not.toBeNull()

		// Right edge of panel must be near the right edge of viewport (within 30px)
		expect(panelBox.x + panelBox.width).toBeGreaterThanOrEqual(viewport.width - 30)

		// Left edge of panel must be well right of the viewport center
		// (panel width ~500px; viewport is typically ≥1280px in CI)
		expect(panelBox.x).toBeGreaterThan(viewport.width / 2)

		// ── Board card still visible & interactive while panel is open ────────
		// The card is in the left column (not under the right-docked panel) and
		// must remain visible with pointer events (panel must not block it).
		await expect(card).toBeVisible()
		const pointerEvents = await card.evaluate((el) => getComputedStyle(el).pointerEvents)
		expect(pointerEvents).not.toBe('none')
		// The card is not covered by the panel: its left edge sits left of the panel.
		const cardBox = await card.boundingBox()
		expect(cardBox.x).toBeLessThan(panelBox.x)

		// ── Escape key closes the panel ───────────────────────────────────────
		await page.keyboard.press('Escape')
		await expect(panel).not.toBeVisible({ timeout: 3_000 })
	})

	test('close button (×) inside panel header dismisses the panel', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)

		await page.locator('.card-tile').first().waitFor({ state: 'visible', timeout: 20_000 })

		// Open panel via the ⋯ More overflow menu → Board settings.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		const panel = page.locator('.bs-modal')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		// Click the close (×) button inside the settings modal header
		await page.locator('.bs-modal__close').click()
		await expect(panel).not.toBeVisible({ timeout: 3_000 })
	})

	test('Board settings menu item toggles the panel (second invocation closes)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)

		await page.locator('.card-tile').first().waitFor({ state: 'visible', timeout: 20_000 })

		const moreBtn = page.getByRole('button', { name: 'More' })
		const settingsItem = page.getByRole('menuitem', { name: /board settings/i })
		const panel = page.locator('.bs-modal')

		// First invocation opens (Board settings now lives in the ⋯ More overflow
		// menu; selecting it dismisses the menu but leaves the docked panel open).
		await moreBtn.click()
		await settingsItem.click()
		await expect(panel).toBeVisible({ timeout: 5_000 })

		// Second invocation closes (the same handler still toggles). Re-open the
		// menu — its item is hidden while the menu is dismissed — then invoke again.
		await moreBtn.click()
		await settingsItem.click()
		await expect(panel).not.toBeVisible({ timeout: 3_000 })
	})
})

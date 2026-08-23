// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

async function stackColor(boardId, stackId) {
	const board = await api.get(`/boards/${boardId}`)
	return board.stacks.find((s) => s.id === stackId)?.color
}

// #3518 — colorize stacks / columns.
test.describe('Stack colour', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Stack-Colour E2E' })
		state.boardId = board.id
		state.stackId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('picking a colour from the column menu tints the header and persists', async ({ page }) => {
		expect(await stackColor(state.boardId, state.stackId)).toBeFalsy()

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		// Open the column ⋯ menu. NcActions renders a TELEPORTED popover that takes
		// a beat to mount/animate; on the slow CI runner clicking "Red" before the
		// menu is actionable makes the click miss (no PATCH → colour stays null).
		await page.locator('.stack-column__actions button').first().click()

		// Wait for the popover menu to actually open and be visible before we
		// target anything inside it, then scope "Red" to that OPEN menu.
		const redItem = page
			.locator('.v-popper__popper--shown')
			.getByRole('menuitem', { name: /^Red$/ })
			.first()
		// Fall back to the plain text locator scoped to a shown popover if the
		// menuitem role lookup comes up empty (older @nextcloud/vue markup).
		const redFallback = page
			.locator('.v-popper__popper--shown .action-button__text', { hasText: /^Red$/ })
			.first()

		const red = (await redItem.count()) ? redItem : redFallback
		await expect(red).toBeVisible({ timeout: 10_000 })

		// Click "Red" and wait for the PATCH that persists the colour so the
		// assertion below isn't racing an in-flight request on slow infra.
		const [patchResponse] = await Promise.all([
			page.waitForResponse(
				(r) => /\/api\/stacks\/\d+/.test(r.url()) && r.request().method() === 'PATCH',
				{ timeout: 15_000 },
			),
			red.click(),
		])
		expect(patchResponse.ok()).toBeTruthy()

		// Persisted as the bare-hex preset …
		await expect.poll(() => stackColor(state.boardId, state.stackId), { timeout: 10_000 }).toBe('e74c3c')
		// … and the header shows the coloured accent.
		await expect(page.locator('.stack-column__header--colored').first()).toBeVisible({ timeout: 6_000 })
	})
})

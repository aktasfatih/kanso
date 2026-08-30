// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// #10008: `shortcutError` was only ever cleared by the banner's manual ×, so a
// single failed shortcut (a network blip, a refused write, a card that vanished)
// left the board reporting that failure through every later shortcut that
// actually worked. These specs pin that a successful shortcut retires the
// banner on its own, and that a genuine failure still raises it and the × still
// dismisses it.
//
// A viewer never reaches this surface at all — #9978 made 'd' and '0'–'4' no-op
// for read-only members, and that (no banner for a viewer) is pinned in
// keyboard-shortcuts-acl.spec.js.

const CARD_PATCH = /\/apps\/kanso\/api\/cards\/\d+(\?|$)/

/**
 * Reject card PATCHes with a server-shaped error body.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} message - goes in the `error` field, so it is what the banner shows
 * @param {object} [options]
 * @param {boolean} [options.onlyFirst] - fail just the first PATCH, then let later ones
 *   through (the exact shape of the bug: a transient failure, then a shortcut that works)
 */
async function failCardPatch(page, message, { onlyFirst = false } = {}) {
	let failures = 0
	await page.route(CARD_PATCH, async (route) => {
		if (route.request().method() === 'PATCH' && !(onlyFirst && failures > 0)) {
			failures++
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ error: message }),
			})
			return
		}
		await route.continue()
	})
}

test.describe('Shortcut error banner auto-clears on success (#10008)', () => {
	test.use({ viewport: { width: 1600, height: 900 } })

	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Shortcut Banner ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Inbox' })
		await api.post('/cards', { stackId: stack.id, title: 'Banner card' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	/**
	 * Open the board and focus its only card with j.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @return {Promise<import('@playwright/test').Locator>} the focused tile
	 */
	async function openBoardWithFocusedCard(page) {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		const tile = page.locator('.card-tile', { hasText: 'Banner card' })
		await expect(tile).toBeVisible({ timeout: 10_000 })
		await page.keyboard.press('j')
		await expect(tile).toBeFocused({ timeout: 5000 })
		return tile
	}

	test('a priority key that succeeds clears the previous failure banner', async ({ page }) => {
		const tile = await openBoardWithFocusedCard(page)
		await failCardPatch(page, 'Simulated shortcut failure', { onlyFirst: true })

		// First priority key is refused — the banner reports it.
		await page.keyboard.press('3')
		await expect(page.locator('.board-view__move-error'))
			.toContainText('Simulated shortcut failure', { timeout: 10_000 })

		// Second one reaches the server and lands…
		await page.keyboard.press('2')
		await expect(tile.locator('.card-tile__priority'))
			.toHaveClass(/card-tile__priority--2/, { timeout: 10_000 })
		// …so the stale failure must retire itself, with no click on the ×.
		await expect(page.locator('.board-view__move-error')).toHaveCount(0, { timeout: 10_000 })
	})

	// 'd' and the priority keys clear the banner from their own separate success
	// callbacks, so neither one covers the other.
	test('a done key that succeeds clears the previous failure banner', async ({ page }) => {
		const tile = await openBoardWithFocusedCard(page)
		await failCardPatch(page, 'Simulated toggle failure', { onlyFirst: true })

		await page.keyboard.press('d')
		await expect(page.locator('.board-view__move-error'))
			.toContainText('Simulated toggle failure', { timeout: 10_000 })

		await page.keyboard.press('d')
		await expect(tile).toHaveClass(/card-tile--done/, { timeout: 10_000 })
		await expect(page.locator('.board-view__move-error')).toHaveCount(0, { timeout: 10_000 })
	})

	test('a genuine failure still raises the banner, and the × still dismisses it', async ({ page }) => {
		await openBoardWithFocusedCard(page)
		await failCardPatch(page, 'Simulated done failure')

		await page.keyboard.press('d')
		const banner = page.locator('.board-view__move-error')
		await expect(banner).toContainText('Simulated done failure', { timeout: 10_000 })

		await banner.locator('.board-view__move-error-dismiss').click()
		await expect(banner).toHaveCount(0, { timeout: 5000 })
	})
})

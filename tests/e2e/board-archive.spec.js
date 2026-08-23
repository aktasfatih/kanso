// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

async function boardArchived(id) {
	const boards = await api.send('GET', '/boards')
	return boards.find((b) => b.id === id)?.archived
}

// #3514 — board archiving.
test.describe('Board archiving', () => {
	const state = { boardId: 0, title: '' }

	test.beforeAll(async () => {
		state.title = 'Archive-Me ' + Math.floor(Date.now() / 1000)
		const board = await api.send('POST', '/boards', { title: state.title })
		state.boardId = board.id
		await api.send('POST', '/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.send('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('archive from settings hides the board, then unarchive restores it', async ({ page }) => {
		expect(await boardArchived(state.boardId)).toBe(false)

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings → General tab → Archive (board actions moved into the
		// General tab's danger zone; #3614). Board settings now lives in the
		// consolidated ⋯ More overflow menu. The modal opens on the labels tab, so
		// switch to General first.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: 'General' }).click()
		const general = page.locator('#bs-pane-general')
		await general.locator('.board-actions__danger').getByRole('button', { name: 'Archive' }).click()

		// Persisted archived + landed on the board list.
		await expect.poll(() => boardArchived(state.boardId), { timeout: 8_000 }).toBe(true)
		await page.waitForSelector('.board-grid, .board-list-segmented', { timeout: 10_000 })

		// The board is no longer an active tile …
		await expect(page.locator('.board-tile__title', { hasText: state.title })).toHaveCount(0)
		// … but shows once the Archived segment of the toggle is selected.
		await page.getByRole('button', { name: /Archived/ }).click()
		const row = page.locator('.board-list__archived-row', { hasText: state.title })
		await expect(row).toBeVisible({ timeout: 6_000 })

		// Unarchive restores it to active. The standalone Unarchive button is
		// folded into the tile's options (⋯) menu (#3750).
		await row.locator(`[data-test="board-options-menu-${state.boardId}"] button`).first().click()
		await page.locator(`[data-test="tile-unarchive-${state.boardId}"]`).first().click()
		await expect.poll(() => boardArchived(state.boardId), { timeout: 8_000 }).toBe(false)
		// Back on the Active segment, the board reappears as a tile.
		await page.getByRole('button', { name: 'Active', exact: true }).click()
		await expect(page.locator('.board-tile__title', { hasText: state.title })).toBeVisible({ timeout: 6_000 })
	})
})

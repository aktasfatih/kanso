// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

// doneAt for each card in the stack, keyed by title (from the board summary).
async function doneByTitle(boardId, stackId) {
	const board = await api.get(`/boards/${boardId}`)
	const out = {}
	for (const c of board.cards) {
		if (c.stackId === stackId && !c.archived) out[c.title] = Number(c.doneAt) > 0
	}
	return out
}

// #4045 — multi-select cards, then bulk "Mark done" via the bulk action bar.
test.describe('Bulk mark done (multi-select)', () => {
	const state = { boardId: 0, todoId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: 'Bulk-Mark-Done E2E' })
		state.boardId = board.id
		state.todoId = (await api.post('/stacks', { boardId: board.id, title: 'To Do' })).id
		await api.post('/cards', { stackId: state.todoId, title: 'Alpha' })
		await api.post('/cards', { stackId: state.todoId, title: 'Bravo' })
		await api.post('/cards', { stackId: state.todoId, title: 'Charlie' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('selecting two cards and bulk-marking done stamps them done', async ({ page }) => {
		expect(await doneByTitle(state.boardId, state.todoId))
			.toEqual({ Alpha: false, Bravo: false, Charlie: false })

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column', { timeout: 15_000 })
		await expect(page.locator('.card-tile', { hasText: 'Alpha' })).toBeVisible({ timeout: 10_000 })

		// Enter multi-select mode via the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Select multiple cards' }).click()

		// Select Alpha and Bravo (Charlie stays untouched).
		await page.locator('.card-tile', { hasText: 'Alpha' }).click()
		await page.locator('.card-tile', { hasText: 'Bravo' }).click()

		// The bulk action bar reports the selection count.
		await expect(page.locator('.bulk-action-bar')).toContainText('2')

		// The "Mark done" control renders a visible icon and is clickable.
		const markDone = page.getByRole('button', { name: 'Mark done' })
		await expect(markDone).toBeVisible()
		await expect(markDone.locator('.material-design-icon')).toBeVisible()
		await markDone.click()

		// Both selected cards are stamped done server-side; Charlie is not.
		await expect
			.poll(() => doneByTitle(state.boardId, state.todoId), { timeout: 10_000 })
			.toEqual({ Alpha: true, Bravo: true, Charlie: false })

		// The board reflects the done state on the two tiles.
		await expect(page.locator('.card-tile--done', { hasText: 'Alpha' })).toBeVisible({ timeout: 10_000 })
		await expect(page.locator('.card-tile--done', { hasText: 'Bravo' })).toBeVisible({ timeout: 10_000 })
		await expect(page.locator('.card-tile--done', { hasText: 'Charlie' })).toHaveCount(0)
	})
})

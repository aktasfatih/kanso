// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #10430 — "Archive all cards" in the column ⋯ menu: the one-action replacement
// for select-multiple → select-all → archive-selected. It archives what the
// column currently SHOWS, reuses the existing /api/cards/bulk endpoint, and is
// undoable (the bulk endpoint gained an `unarchive` action for exactly that).

import { test, expect, api, ncLogin, BASE } from './helpers.js'

/**
 * Open the ⋯ NcActions menu for the first column on the page and return the
 * teleported dialog locator (NcActions teleports its panel to <body>).
 *
 * @param {import('@playwright/test').Page} page - the page under test
 * @return {Promise<import('@playwright/test').Locator>} the menu dialog
 */
async function openColumnMenu(page) {
	await page.locator('.stack-column__actions button').first().click()
	const dialog = page.locator('[role="dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 6_000 })
	return dialog
}

const CARDS = ['Archive Me One', 'Archive Me Two', 'Archive Me Three']

test.describe('Archive every card in a column (#10430)', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeEach(async () => {
		const board = await api.post('/boards', { title: `Archive All E2E ${Date.now()}` })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Sprint' })
		state.stackId = stack.id
		for (const title of CARDS) {
			await api.post('/cards', { stackId: stack.id, title })
		}
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterEach(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
		state.boardId = 0
	})

	test('one menu action empties the column and every card lands on the Archived page', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await expect(page.locator('.card-tile')).toHaveCount(3, { timeout: 15_000 })

		// With no filter on, the entry says "all" — it is the truth here.
		const menu = await openColumnMenu(page)
		const archiveAll = menu.getByRole('button', { name: 'Archive all cards' })
		await expect(archiveAll).toBeVisible({ timeout: 8_000 })
		await archiveAll.click()

		// The column empties …
		await expect(page.locator('.card-tile')).toHaveCount(0, { timeout: 15_000 })

		// … and all three are archived server-side, not deleted.
		await expect
			.poll(async () => {
				const board = await api.get(`/boards/${state.boardId}`)
				return board.cards.filter((c) => c.archived).map((c) => c.title).sort()
			}, { timeout: 15_000 })
			.toEqual([...CARDS].sort())

		// They show on the routed Archived page.
		await page.goto(`${state.boardUrl}/archived`)
		await page.waitForSelector('.archived-view', { timeout: 15_000 })
		for (const title of CARDS) {
			await expect(page.locator('.archived-view__row-title').filter({ hasText: title }))
				.toBeVisible({ timeout: 10_000 })
		}
	})

	test('the undo toast puts every archived card back on the board', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await expect(page.locator('.card-tile')).toHaveCount(3, { timeout: 15_000 })

		const menu = await openColumnMenu(page)
		await menu.getByRole('button', { name: 'Archive all cards' }).click()
		await expect(page.locator('.card-tile')).toHaveCount(0, { timeout: 15_000 })

		// showUndo renders a .toast-undo toastify toast whose label carries the
		// count — 10-100x the blast radius of a single archive is exactly why this
		// action needs a real undo rather than a confirm dialog.
		// @nextcloud/dialogs gives an undo toast a 10s life, so assert on it with a
		// budget UNDER that — a longer one would report "not visible" for a toast
		// that appeared and simply expired, which reads as the wrong failure.
		const undoToast = page.locator('.toast-undo')
		await expect(undoToast).toBeVisible({ timeout: 8_000 })
		await expect(undoToast).toContainText('3 cards archived')

		const undoBtn = undoToast.locator('button').filter({ hasText: 'Undo' })
		await expect(undoBtn).toBeVisible({ timeout: 5_000 })
		await undoBtn.click()

		// All three come back to the column …
		await expect(page.locator('.card-tile')).toHaveCount(3, { timeout: 20_000 })
		// … and nothing is left archived (the undo is a real unarchive write, not a
		// client-side rollback).
		await expect
			.poll(async () => {
				const board = await api.get(`/boards/${state.boardId}`)
				return board.cards.filter((c) => c.archived).length
			}, { timeout: 15_000 })
			.toBe(0)
	})

	test('the entry is hidden on an empty column and names the visible count under a filter', async ({ page }) => {
		// A second, empty column: nothing to archive, so no entry at all.
		await api.post('/stacks', { boardId: state.boardId, title: 'Empty' })
		// A label on ONE card, so a label filter narrows "all" to a strict subset.
		const label = await api.post('/labels', { boardId: state.boardId, title: 'Keep', color: '31CC7C' })
		const board = await api.get(`/boards/${state.boardId}`)
		const one = board.cards.find((c) => c.title === CARDS[0])
		await api.put(`/cards/${one.id}/labels/${label.id}`)

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await expect(page.locator('.card-tile')).toHaveCount(3, { timeout: 15_000 })

		// The empty column's menu offers no archive entry.
		const emptyColumn = page.locator('.stack-column').filter({ hasText: 'Empty' })
		await emptyColumn.locator('.stack-column__actions button').first().click()
		const emptyMenu = page.locator('[role="dialog"]').first()
		await expect(emptyMenu).toBeVisible({ timeout: 6_000 })
		await expect(emptyMenu.getByRole('button', { name: /^Archive/ })).toHaveCount(0)
		await page.keyboard.press('Escape')

		// Filter to the one labelled card (straight from the URL, the shareable
		// form the filter bar itself writes): the entry must stop claiming "all".
		await page.goto(`${state.boardUrl}?fl=${label.id}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page.locator('.card-tile')).toHaveCount(1, { timeout: 10_000 })

		const menu = await openColumnMenu(page)
		await expect(menu.getByRole('button', { name: 'Archive 1 visible card' }))
			.toBeVisible({ timeout: 8_000 })
		await expect(menu.getByRole('button', { name: 'Archive all cards' })).toHaveCount(0)
	})
})

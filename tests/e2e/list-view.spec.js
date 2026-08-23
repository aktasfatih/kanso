// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Board List view (#3444)', () => {
	const state = { boardId: 0, title: 'List View ' + Math.floor(Date.now() / 1000), cardTitle: 'List row card' }

	test.beforeAll(async () => {
		const board = await api.post('/boards', { title: state.title })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To do' })
		await api.post('/cards', { stackId: stack.id, title: state.cardTitle })
		// A second card with a due date well in the past → the group header should
		// surface an "overdue" hint for it (variant 1d per-group hints).
		const overdue = await api.post('/cards', { stackId: stack.id, title: 'Overdue row card' })
		await api.patch(`/cards/${overdue.id}`, { duedate: '2020-01-01T00:00:00+00:00' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('switches to List, renders card rows, opens a card, and switches back', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		const setView = async (name) => {
			await page.locator('.board-view__display-menu button').first().click()
			await page.getByRole('menuitemradio', { name, exact: true }).click()
			// Close the popover so the next open() is a fresh open, not a toggle-shut.
			await page.keyboard.press('Escape')
		}

		// Switch to List → card renders as a row, Board columns hidden.
		await setView('List')
		const row = page.locator('.board-list-row', { hasText: state.cardTitle })
		await expect(row).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.board-view__stacks-wrap')).toBeHidden()

		// The group header surfaces a per-group overdue hint for the past-due card.
		await expect(page.locator('.board-list-group__hint--overdue', { hasText: 'overdue' }))
			.toBeVisible({ timeout: 8_000 })

		// Toggle back to Board → columns visible again (round-trip, no modal open).
		await setView('Board')
		await expect(page.locator('.board-view__stacks-wrap')).toBeVisible({ timeout: 8_000 })

		// Back to List, then open a card. dispatchEvent fires the handler directly:
		// the row is a virtualized item on a list that refreshes on the board poll,
		// so a coordinate click can race the re-render.
		await setView('List')
		await expect(row).toBeVisible({ timeout: 8_000 })
		await row.dispatchEvent('click')
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/`), { timeout: 8_000 })
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })
	})

	test('collapsing a group hides its cards; expanding shows them again', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Switch to List view.
		await page.locator('.board-view__display-menu button').first().click()
		await page.getByText('List', { exact: true }).click()

		const row = page.locator('.board-list-row', { hasText: state.cardTitle })
		const group = page.locator('.board-list-group', { hasText: 'To do' })
		await expect(row).toBeVisible({ timeout: 8_000 })
		await expect(group).toBeVisible()

		// Collapse the group → its card row disappears from the virtualized list.
		await group.dispatchEvent('click')
		await expect(row).toBeHidden({ timeout: 8_000 })
		await expect(group).toBeVisible()

		// Expand again → the card row comes back.
		await group.dispatchEvent('click')
		await expect(row).toBeVisible({ timeout: 8_000 })
	})
})

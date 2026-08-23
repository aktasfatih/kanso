// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

const USER = 'admin'

// #3597 — closing a card opened from My Work must return to My Work, not the
// board; while a card opened from the board (or a pasted deep link) must still
// close to the board.
test.describe('Card close returns to its origin', () => {
	const BOARD_TITLE = 'My Work Return E2E ' + Date.now()
	const state = { boardId: 0, stackId: 0, cardId: 0 }

	test.beforeAll(async () => {
		// Tear down any stale board with the same name
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) await api.delete(`/boards/${b.id}`)
		}

		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id

		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id

		const card = await api.post('/cards', {
			stackId: stack.id,
			title: 'Return Target Card',
			description: 'Used to verify close-to-origin routing.',
		})
		state.cardId = card.id

		// Assign to admin so the card surfaces in the "My tasks" feed.
		await api.put(`/cards/${card.id}/assignees/${USER}`)
	})

	test.afterAll(async () => {
		if (state.boardId) await api.delete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('My Work → open card → close returns to My Work (not the board)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)

		// Land on the My Work hub (My tasks tab is the default).
		await page.goto(`${BASE}/index.php/apps/kanso#/my-work`)
		await expect(page.getByRole('heading', { name: 'My Work' })).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 10_000 })

		// Open the assigned card from the My tasks list.
		await page.getByText('Return Target Card').first().click()

		// The card modal opens with the origin threaded in the query.
		await expect(page).toHaveURL(/#\/board\/\d+\/card\/\d+\?from=my-work/, { timeout: 10_000 })
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })

		// Close the modal via Escape.
		await page.keyboard.press('Escape')

		// We must land back on the My Work hub, NOT on the board.
		await expect(page).toHaveURL(/#\/my-work/, { timeout: 10_000 })
		await expect(page).not.toHaveURL(/#\/board\//)
		await expect(page.getByRole('heading', { name: 'My Work' })).toBeVisible({ timeout: 10_000 })
	})

	test('Board → open card → close returns to the board (no regression)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
		await ncLogin(page)

		// Deep-link straight to the card (mirrors a board-opened / pasted URL —
		// no `from` query).
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}/card/${state.cardId}`)
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })

		await page.keyboard.press('Escape')

		// Must close to the board, not to any My Work surface.
		await expect(page).toHaveURL(
			new RegExp(`#/board/${state.boardId}$`),
			{ timeout: 10_000 },
		)
		await expect(page).not.toHaveURL(/#\/my-work/)
	})
})

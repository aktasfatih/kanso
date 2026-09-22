// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, toast, BASE } from './helpers.js'

// ── Test suite ───────────────────────────────────────────────────────────────

test.describe('Undo toasts', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same title to keep tests hermetic.
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Undo E2E Test Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card.
		const board = await api.post('/boards', { title: 'Undo E2E Test Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Undo Test Stack' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Undo Test Card' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		try {
			if (state.boardId) {
				await api.delete(`/boards/${state.boardId}`)
			}
		} catch {}
	})

	test('deleting a card shows an Undo toast; clicking Undo restores the card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Open the card modal by clicking the card tile.
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Undo Test Card' })
		await expect(cardTile).toBeVisible()
		await cardTile.click()

		// Wait for the modal to appear.
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Open the actions (⋯) menu inside the modal.
		const actionsMenu = page.locator('.card-modal__actions-menu')
		await expect(actionsMenu).toBeVisible()
		await actionsMenu.click()

		// Click Delete in the actions dropdown - no confirm banner, just immediate delete.
		const deleteBtn = page.locator('[role="menuitem"]').filter({ hasText: 'Delete' })
		await expect(deleteBtn).toBeVisible()
		await deleteBtn.click()

		// Modal should have closed.
		await expect(page.locator('.card-modal')).not.toBeVisible({ timeout: 8_000 })

		// The undo toast should appear, saying the card was deleted. Located by
		// role + message (see toast() in helpers.js) — never by @nextcloud/dialogs'
		// own class names, which change from release to release.
		const undoToast = toast(page, 'Card deleted')
		await expect(undoToast).toBeVisible()

		// Click the Undo button inside the toast.
		const undoBtn = undoToast.getByRole('button', { name: 'Undo' })
		await expect(undoBtn).toBeVisible()
		await undoBtn.click()

		// The toast should dismiss.
		await expect(undoToast).not.toBeVisible({ timeout: 8_000 })

		// After undo (restore), the board query is invalidated and the card should
		// reappear on the board. Allow generous time for the network round-trip.
		const restoredTile = page.locator('.card-tile').filter({ hasText: 'Undo Test Card' })
		await expect(restoredTile).toBeVisible({ timeout: 15_000 })
	})
})

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

const USER = 'admin'

test.describe('Card review flow', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '', boardUrl: '' }

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === 'Review E2E Board') {
				await api.delete(`/boards/${b.id}`)
			}
		}

		const board = await api.post('/boards', { title: 'Review E2E Board' })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Card Under Review' })
		state.cardId = card.id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${card.id}`
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`

		// The board owner requests a review from themselves - a valid single-user
		// path (owner holds READ). Gives the card one pending review to drive the UI.
		await api.put(`/cards/${card.id}/reviews/${USER}`)
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('modal shows the pending review chip and a verdict prompt', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		const chip = page.locator('.card-modal__review-pill--pending')
		await expect(chip).toBeVisible({ timeout: 6000 })
		await expect(chip.locator('.card-modal__review-state--pending')).toContainText('Pending')

		// The current user is the pending reviewer, so the verdict banner shows.
		const verdict = page.locator('.card-modal__verdict')
		await expect(verdict).toBeVisible({ timeout: 4000 })
		await expect(verdict.getByRole('button', { name: 'Approve' })).toBeVisible()
		await expect(verdict.getByRole('button', { name: 'Request changes' })).toBeVisible()
	})

	test('approving flips the review chip to approved', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		await page.locator('.card-modal__verdict').getByRole('button', { name: 'Approve' }).click()

		await expect(page.locator('.card-modal__review-pill--approved')).toBeVisible({ timeout: 6000 })
		// Once approved, the "needs verdict" banner is gone.
		await expect(page.locator('.card-modal__verdict')).toHaveCount(0, { timeout: 4000 })
	})

	test('board tile shows the review-state chip', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// After the approval above, the tile carries an approved review chip.
		await expect(page.locator('.card-tile__review--approved').first()).toBeVisible({ timeout: 8000 })
	})
})

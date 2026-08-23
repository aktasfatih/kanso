// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Human-readable card identifiers', () => {
	// Title "Kanban Reference …" → derived prefix "KANBA".
	const BOARD_TITLE = 'Kanban Reference ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		firstCardId: 0,
		secondCardId: 0,
		prefix: '',
		boardUrl: '',
	}

	test.beforeAll(async () => {
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Kanban Reference')) {
				await api.delete(`/boards/${b.id}`)
			}
		}

		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		state.prefix = board.prefix // derived from the title, e.g. "KANBA"
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		const first = await api.post('/cards', { stackId: stack.id, title: 'First reference card' })
		state.firstCardId = first.id
		const second = await api.post('/cards', { stackId: stack.id, title: 'Second reference card' })
		state.secondCardId = second.id

		// Per-board sequence is 1-based and increments per create.
		expect(first.boardSeq).toBe(1)
		expect(second.boardSeq).toBe(2)
		expect(state.prefix).toBeTruthy()

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('the KAN-<n> reference shows on the card tile', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const firstRef = `${state.prefix}-1`
		const secondRef = `${state.prefix}-2`

		const firstTileRef = page.locator('.card-tile')
			.filter({ hasText: 'First reference card' })
			.locator('.card-tile__ref')
		await expect(firstTileRef).toBeVisible({ timeout: 5000 })
		await expect(firstTileRef).toHaveText(firstRef)

		const secondTileRef = page.locator('.card-tile')
			.filter({ hasText: 'Second reference card' })
			.locator('.card-tile__ref')
		await expect(secondTileRef).toHaveText(secondRef)
	})

	test('the reference shows in the card modal header and is copyable', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const firstRef = `${state.prefix}-1`

		const tile = page.locator('.card-tile').filter({ hasText: 'First reference card' })
		await expect(tile).toBeVisible({ timeout: 5000 })
		await tile.click()

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The copyable reference button lives in the modal breadcrumb.
		const refButton = page.locator('.card-modal__ref')
		await expect(refButton).toBeVisible({ timeout: 5000 })
		await expect(refButton).toHaveText(firstRef)

		// Clicking copies it - a success toast appears (clipboard itself is not
		// readable headless, but the click path must not error).
		await refButton.click()
		await expect(refButton).toBeVisible()
	})
})

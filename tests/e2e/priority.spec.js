// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE } from './helpers.js'

test.describe('Card priorities', () => {
	// Unique board title to avoid collisions with parallel test runs
	const BOARD_TITLE = 'Priority Test Board ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		highCardId: 0,
		urgentCardId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same title prefix for hermeticity
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Priority Test Board')) {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + two cards
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		const highCard = await api.post('/cards', { stackId: stack.id, title: 'High Priority Card' })
		state.highCardId = highCard.id

		const urgentCard = await api.post('/cards', { stackId: stack.id, title: 'Urgent Priority Card' })
		state.urgentCardId = urgentCard.id

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('set priority to High via the card modal UI; assert tile shows indicator', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Open the "High Priority Card" modal
		const cardTile = page.locator('.card-tile').filter({ hasText: 'High Priority Card' })
		await expect(cardTile).toBeVisible()
		await cardTile.click()

		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// The priority pill lives in the attribute bar - it's the first pill.
		const attrbar = page.locator('.card-modal__attrbar')
		const priorityPill = attrbar.locator('button.card-modal__pill').first()
		await expect(priorityPill).toBeVisible()

		// Open the priority popover and pick "High"
		await priorityPill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^High$/ }).click()

		// The pill should pick up the --priority-3 modifier
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toBeVisible()

		// Close the modal
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The tile should now show a priority indicator badge
		const priorityBadge = page.locator('.card-tile')
			.filter({ hasText: 'High Priority Card' })
			.locator('.card-tile__priority')
		await expect(priorityBadge).toBeVisible()
		// High is priority level 3 - badge should carry the --3 class
		await expect(priorityBadge).toHaveClass(/card-tile__priority--3/)
	})

	test('set Urgent card priority via UI; assert its tile shows urgent indicator', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Open the "Urgent Priority Card" modal
		const urgentTile = page.locator('.card-tile').filter({ hasText: 'Urgent Priority Card' })
		await expect(urgentTile).toBeVisible()
		await urgentTile.click()

		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// Open the priority popover and pick "Urgent" (level 4)
		const attrbar = page.locator('.card-modal__attrbar')
		const priorityPill = attrbar.locator('button.card-modal__pill').first()
		await expect(priorityPill).toBeVisible()
		await priorityPill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^Urgent$/ }).click()
		await expect(attrbar.locator('.card-modal__pill--priority-4')).toBeVisible()

		// Close the modal
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The tile should show the urgent badge
		const urgentBadge = page.locator('.card-tile')
			.filter({ hasText: 'Urgent Priority Card' })
			.locator('.card-tile__priority--4')
		await expect(urgentBadge).toBeVisible()
	})

	test('filter to Urgent only - High card is hidden; clear filter restores it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// Both cards should be visible initially
		await expect(page.locator('.card-tile').filter({ hasText: 'High Priority Card' }))
			.toBeVisible()
		await expect(page.locator('.card-tile').filter({ hasText: 'Urgent Priority Card' }))
			.toBeVisible()

		// Open the filter popover and drill into the Priority dimension (#3785).
		const filterMenu = page.locator('.board-filter-bar__filter button').first()
		await expect(filterMenu).toBeVisible()
		await filterMenu.click()
		await page.locator('.board-filter-bar__dim-row[data-dim="priorities"]').click()

		// Click the "Urgent" priority filter option (level 4)
		const urgentFilterCheckbox = page.locator('.board-filter-bar__priority-item--4')
		await expect(urgentFilterCheckbox).toBeVisible()
		await urgentFilterCheckbox.click()

		// Close the popover by pressing Escape
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// The Urgent card should be visible, High card should be hidden
		await expect(page.locator('.card-tile').filter({ hasText: 'Urgent Priority Card' }))
			.toBeVisible()
		await expect(page.locator('.card-tile').filter({ hasText: 'High Priority Card' }))
			.not.toBeVisible({ timeout: 5000 })

		// Clear the filter by reopening, drilling into Priority, and unchecking Urgent.
		await filterMenu.click()
		await page.locator('.board-filter-bar__dim-row[data-dim="priorities"]').click()
		const urgentAgain = page.locator('.board-filter-bar__priority-item--4')
		await expect(urgentAgain).toBeVisible()
		await urgentAgain.click()
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// Both cards should be visible again
		await expect(page.locator('.card-tile').filter({ hasText: 'High Priority Card' }))
			.toBeVisible()
		await expect(page.locator('.card-tile').filter({ hasText: 'Urgent Priority Card' }))
			.toBeVisible()
	})

	test('priority persists after page reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 15_000 })

		// After reload the High Priority Card tile should still carry the badge
		const highPriorityBadge = page.locator('.card-tile')
			.filter({ hasText: 'High Priority Card' })
			.locator('.card-tile__priority--3')
		await expect(highPriorityBadge).toBeVisible()

		// Open the modal and verify the High button is still marked active
		const highTile = page.locator('.card-tile').filter({ hasText: 'High Priority Card' })
		await highTile.click()
		await page.waitForSelector('.card-modal', { timeout: 15_000 })

		// The priority pill should still carry the --priority-3 modifier
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--priority-3'))
			.toBeVisible()
	})
})

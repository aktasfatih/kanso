// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect, api, ncLogin, BASE, me } from './helpers.js'

test.describe('CardTile compact layout', () => {
	const suffix = Math.random().toString(36).slice(2, 8)
	const BOARD_TITLE = `Tile Layout Test ${suffix}`
	const state = { boardId: 0, stackId: 0, cardId: 0, labelId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		// Tear down any leftover board with the same name
		const boards = await api.get('/boards')
		for (const b of boards) {
			if (b.title === BOARD_TITLE) {
				await api.delete(`/boards/${b.id}`)
			}
		}

		// Create board + stack + card
		const board = await api.post('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await api.post('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const card = await api.post('/cards', { stackId: stack.id, title: 'Meta Row Card' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`

		// Set priority = 3 (High)
		await api.patch(`/cards/${card.id}`, { priority: 3 })

		// Create a label and assign it to the card
		const label = await api.post('/labels', { boardId: board.id, title: 'Bug', color: 'e07b00' })
		state.labelId = label.id
		await api.put(`/cards/${card.id}/labels/${label.id}`)

		// Assign the current user to the card
		await api.put(`/cards/${card.id}/assignees/${me}`)

		// Add two checklist items via the checklist API
		await api.post(`/cards/${card.id}/checklist`, { title: 'Item one' })
		await api.post(`/cards/${card.id}/checklist`, { title: 'Item two' })
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await api.delete(`/boards/${state.boardId}`)
		}
	})

	test('priority badge, checklist badge, and label chip are all visible and on the same row', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const tile = page.locator('.card-tile').filter({ hasText: 'Meta Row Card' })
		await expect(tile).toBeVisible({ timeout: 5000 })

		// Assert priority badge is visible
		const priorityBadge = tile.locator('.card-tile__priority')
		await expect(priorityBadge).toBeVisible({ timeout: 5000 })

		// Assert checklist badge is visible (0/2 initially since no items are checked)
		const checklistBadge = tile.locator('.card-tile__checklist')
		await expect(checklistBadge).toBeVisible({ timeout: 5000 })
		await expect(checklistBadge).toHaveText(/0\/2/)

		// Assert label chip is visible
		const labelChip = tile.locator('.card-tile__label-chip')
		await expect(labelChip).toBeVisible({ timeout: 5000 })

		// Assert all badges are on roughly the same row:
		// The priority badge and checklist badge should be inside .card-tile__meta
		// so their Y positions must be within ~30px of each other.
		const metaRow = tile.locator('.card-tile__meta')
		await expect(metaRow).toBeVisible({ timeout: 5000 })

		const priorityBox = await priorityBadge.boundingBox()
		const checklistBox = await checklistBadge.boundingBox()
		expect(priorityBox).not.toBeNull()
		expect(checklistBox).not.toBeNull()
		const yDiff = Math.abs(priorityBox.y - checklistBox.y)
		expect(yDiff).toBeLessThan(30)

		// Assert tile total height is compact (< 160px)
		const tileBox = await tile.boundingBox()
		expect(tileBox).not.toBeNull()
		expect(tileBox.height).toBeLessThan(160)
	})

	test('tile opens card modal on click', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const tile = page.locator('.card-tile').filter({ hasText: 'Meta Row Card' })
		await expect(tile).toBeVisible({ timeout: 5000 })
		await tile.click()

		// Card modal should appear
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 5000 })

		// Close it
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})
	})

	test('assignee avatars are pushed to the right of the meta row', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const tile = page.locator('.card-tile').filter({ hasText: 'Meta Row Card' })
		await expect(tile).toBeVisible({ timeout: 5000 })

		const assignees = tile.locator('.card-tile__assignees')
		await expect(assignees).toBeVisible({ timeout: 5000 })

		// Assignees should be inside the meta row
		const metaRow = tile.locator('.card-tile__meta')
		const metaBox = await metaRow.boundingBox()
		const assigneesBox = await assignees.boundingBox()
		const priorityBox = await tile.locator('.card-tile__priority').boundingBox()

		expect(metaBox).not.toBeNull()
		expect(assigneesBox).not.toBeNull()
		expect(priorityBox).not.toBeNull()

		// Assignees should be further right than priority badge
		expect(assigneesBox.x).toBeGreaterThan(priorityBox.x)

		// Assignees right edge should be close to meta row right edge (within 20px)
		const assigneesRight = assigneesBox.x + assigneesBox.width
		const metaRight = metaBox.x + metaBox.width
		expect(metaRight - assigneesRight).toBeLessThan(20)
	})
})

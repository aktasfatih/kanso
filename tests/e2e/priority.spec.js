// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = {
	'OCS-APIREQUEST': 'true',
	'Content-Type': 'application/json',
}
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiGet(path) {
	const r = await fetch(API + path, { headers: { ...HEADERS, Authorization: AUTH } })
	if (!r.ok) throw new Error(`GET ${path} → ${r.status}`)
	return r.json()
}

async function apiPost(path, body) {
	const r = await fetch(API + path, {
		method: 'POST',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`POST ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiDelete(path) {
	const r = await fetch(API + path, {
		method: 'DELETE',
		headers: { ...HEADERS, Authorization: AUTH },
	})
	if (!r.ok) throw new Error(`DELETE ${path} → ${r.status}`)
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})

	const userInput = page.locator('#user')
	const isLoginPage = await userInput.isVisible({ timeout: 3000 }).catch(() => false)
	if (!isLoginPage) return // Already logged in

	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

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
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Priority Test Board')) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + two cards
		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id

		const highCard = await apiPost('/cards', { stackId: stack.id, title: 'High Priority Card' })
		state.highCardId = highCard.id

		const urgentCard = await apiPost('/cards', { stackId: stack.id, title: 'Urgent Priority Card' })
		state.urgentCardId = urgentCard.id

		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('set priority to High via the card modal UI; assert tile shows indicator', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open the "High Priority Card" modal
		const cardTile = page.locator('.card-tile').filter({ hasText: 'High Priority Card' })
		await expect(cardTile).toBeVisible({ timeout: 5000 })
		await cardTile.click()

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The priority pill lives in the attribute bar - it's the first pill.
		const attrbar = page.locator('.card-modal__attrbar')
		const priorityPill = attrbar.locator('button.card-modal__pill').first()
		await expect(priorityPill).toBeVisible({ timeout: 5000 })

		// Open the priority popover and pick "High"
		await priorityPill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^High$/ }).click()

		// The pill should pick up the --priority-3 modifier
		await expect(attrbar.locator('.card-modal__pill--priority-3')).toBeVisible({ timeout: 5000 })

		// Close the modal
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The tile should now show a priority indicator badge
		const priorityBadge = page.locator('.card-tile')
			.filter({ hasText: 'High Priority Card' })
			.locator('.card-tile__priority')
		await expect(priorityBadge).toBeVisible({ timeout: 5000 })
		// High is priority level 3 - badge should carry the --3 class
		await expect(priorityBadge).toHaveClass(/card-tile__priority--3/, { timeout: 3000 })
	})

	test('set Urgent card priority via UI; assert its tile shows urgent indicator', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open the "Urgent Priority Card" modal
		const urgentTile = page.locator('.card-tile').filter({ hasText: 'Urgent Priority Card' })
		await expect(urgentTile).toBeVisible({ timeout: 5000 })
		await urgentTile.click()

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Open the priority popover and pick "Urgent" (level 4)
		const attrbar = page.locator('.card-modal__attrbar')
		const priorityPill = attrbar.locator('button.card-modal__pill').first()
		await expect(priorityPill).toBeVisible({ timeout: 5000 })
		await priorityPill.click()
		await page.locator('.card-modal__popover .card-modal__popover-opt', { hasText: /^Urgent$/ }).click()
		await expect(attrbar.locator('.card-modal__pill--priority-4')).toBeVisible({ timeout: 5000 })

		// Close the modal
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The tile should show the urgent badge
		const urgentBadge = page.locator('.card-tile')
			.filter({ hasText: 'Urgent Priority Card' })
			.locator('.card-tile__priority--4')
		await expect(urgentBadge).toBeVisible({ timeout: 5000 })
	})

	test('filter to Urgent only - High card is hidden; clear filter restores it', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Both cards should be visible initially
		await expect(page.locator('.card-tile').filter({ hasText: 'High Priority Card' }))
			.toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Urgent Priority Card' }))
			.toBeVisible({ timeout: 5000 })

		// Open the filter menu (the filter toggle, not the adjacent saved-views one)
		const filterMenu = page.locator('.board-filter-bar__filter button').first()
		await expect(filterMenu).toBeVisible({ timeout: 5000 })
		await filterMenu.click()

		// Click the "Urgent" priority filter checkbox (level 4)
		const urgentFilterCheckbox = page.locator('.board-filter-bar__priority-item--4')
		await expect(urgentFilterCheckbox).toBeVisible({ timeout: 5000 })
		await urgentFilterCheckbox.click()

		// Close the menu by pressing Escape
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// The Urgent card should be visible, High card should be hidden
		await expect(page.locator('.card-tile').filter({ hasText: 'Urgent Priority Card' }))
			.toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'High Priority Card' }))
			.not.toBeVisible({ timeout: 5000 })

		// Clear the filter by reopening the menu and unchecking Urgent (robust -
		// avoids the teleported "Clear filters" NcActionButton).
		await filterMenu.click()
		const urgentAgain = page.locator('.board-filter-bar__priority-item--4')
		await expect(urgentAgain).toBeVisible({ timeout: 5000 })
		await urgentAgain.click()
		await page.keyboard.press('Escape')
		await page.waitForTimeout(300)

		// Both cards should be visible again
		await expect(page.locator('.card-tile').filter({ hasText: 'High Priority Card' }))
			.toBeVisible({ timeout: 5000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Urgent Priority Card' }))
			.toBeVisible({ timeout: 5000 })
	})

	test('priority persists after page reload', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// After reload the High Priority Card tile should still carry the badge
		const highPriorityBadge = page.locator('.card-tile')
			.filter({ hasText: 'High Priority Card' })
			.locator('.card-tile__priority--3')
		await expect(highPriorityBadge).toBeVisible({ timeout: 8000 })

		// Open the modal and verify the High button is still marked active
		const highTile = page.locator('.card-tile').filter({ hasText: 'High Priority Card' })
		await highTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The priority pill should still carry the --priority-3 modifier
		await expect(page.locator('.card-modal__attrbar .card-modal__pill--priority-3'))
			.toBeVisible({ timeout: 5000 })
	})
})

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

test.describe('Checklist', () => {
	const state = { boardId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		// Tear down any prior board with the same name to ensure hermetic setup
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Checklist Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card
		const board = await apiPost('/boards', { title: 'Checklist Test Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Card With Checklist' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test('add two checklist items via UI, toggle one done, assert progress and persistence', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open the card modal by clicking the card tile
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await expect(cardTile).toBeVisible({ timeout: 5000 })
		await cardTile.click()

		// Wait for the card modal to open
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Checklist section should be visible
		const checklistSection = page.locator('.card-modal__checklist')
		await expect(checklistSection).toBeVisible({ timeout: 5000 })

		// Add first item "Buy groceries" via the add input
		const addInput = page.locator('.card-modal__checklist-add-input')
		await addInput.fill('Buy groceries')
		await addInput.press('Enter')

		// Wait for the item to appear in the list
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' }))
			.toBeVisible({ timeout: 5000 })

		// Add second item "Write tests"
		await addInput.fill('Write tests')
		await addInput.press('Enter')

		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Write tests' }))
			.toBeVisible({ timeout: 5000 })

		// Assert progress shows 0/2 initially
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('0 / 2', { timeout: 3000 })

		// Toggle "Buy groceries" done by clicking its checkbox
		const buyGroceriesItem = page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' })
		const checkbox = buyGroceriesItem.locator('.card-modal__checklist-checkbox')
		await checkbox.check()

		// Progress should update to 1/2
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('1 / 2', { timeout: 5000 })

		// The progress bar should be visible and partially filled
		await expect(page.locator('.card-modal__checklist-bar')).toBeVisible()
		await expect(page.locator('.card-modal__checklist-bar-fill')).toBeVisible()

		// Close the modal by pressing Escape or clicking outside
		await page.keyboard.press('Escape')

		// Wait for modal to close
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The card tile should now show a checklist badge with 1/2
		await expect(
			page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
				.locator('.card-tile__checklist'),
		).toHaveText(/1\/2/, { timeout: 5000 })

		// Re-open the board fresh and assert persistence (navigate to the board
		// URL rather than page.reload() so the check is independent of the
		// post-Escape route).
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Tile badge should still show 1/2 after reload
		const tileAfterReload = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await expect(tileAfterReload.locator('.card-tile__checklist'))
			.toHaveText(/1\/2/, { timeout: 8000 })

		// Open the card again and verify modal progress is also 1/2
		await tileAfterReload.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('1 / 2', { timeout: 5000 })

		// Verify items are still present
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' }))
			.toBeVisible()
		await expect(page.locator('.card-modal__checklist-item').filter({ hasText: 'Write tests' }))
			.toBeVisible()

		// Verify the done item still has the line-through style
		const doneItem = page.locator('.card-modal__checklist-item').filter({ hasText: 'Buy groceries' })
		await expect(doneItem.locator('.card-modal__checklist-checkbox')).toBeChecked()
	})

	test('complete all items — badge turns success color, progress bar turns green', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open card modal
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
		await cardTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Toggle "Write tests" done (Buy groceries is already done from previous test)
		const writeTestsItem = page.locator('.card-modal__checklist-item').filter({ hasText: 'Write tests' })
		const checkbox = writeTestsItem.locator('.card-modal__checklist-checkbox')
		await checkbox.check()

		// Progress should show 2/2
		await expect(page.locator('.card-modal__checklist-count'))
			.toHaveText('2 / 2', { timeout: 5000 })

		// Progress bar should have the complete class (green)
		await expect(page.locator('.card-modal__checklist-bar-fill--complete'))
			.toBeVisible({ timeout: 3000 })

		// Close and check tile badge has --complete styling
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		const badge = page.locator('.card-tile').filter({ hasText: 'Card With Checklist' })
			.locator('.card-tile__checklist--complete')
		await expect(badge).toBeVisible({ timeout: 5000 })
		await expect(badge).toHaveText(/2\/2/)
	})
})

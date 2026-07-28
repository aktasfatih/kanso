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

async function apiPut(path, body) {
	const r = await fetch(API + path, {
		method: 'PUT',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`PUT ${path} → ${r.status}: ${await r.text()}`)
	return r.json()
}

async function apiPatch(path, body) {
	const r = await fetch(API + path, {
		method: 'PATCH',
		headers: { ...HEADERS, Authorization: AUTH },
		body: JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`PATCH ${path} → ${r.status}: ${await r.text()}`)
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

test.describe('Parent / Child cards', () => {
	// Unique board title to avoid collisions with parallel test runs
	const BOARD_TITLE = 'Parent Child Test Board ' + Date.now()
	const state = {
		boardId: 0,
		stackId: 0,
		parentCardId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same title prefix for safety
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title.startsWith('Parent Child Test Board')) {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + parent card
		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		state.stackId = stack.id
		const parentCard = await apiPost('/cards', { stackId: stack.id, title: 'Parent Card' })
		state.parentCardId = parentCard.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiDelete(`/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('add two sub-cards via UI, assert Children section shows 2 items and progress 0/2', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open the parent card modal
		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await expect(parentTile).toBeVisible({ timeout: 5000 })
		await parentTile.click()

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The add sub-card input should be present (this card has no parent)
		const addChildInput = page.getByPlaceholder('Add a sub-card…')
		await expect(addChildInput).toBeVisible({ timeout: 5000 })

		// Add first sub-card "Sub-task Alpha"
		await addChildInput.fill('Sub-task Alpha')
		await addChildInput.press('Enter')

		// Wait for the child to appear in the list
		await expect(page.locator('.card-modal__child').filter({ hasText: 'Sub-task Alpha' }))
			.toBeVisible({ timeout: 8000 })

		// Add second sub-card "Sub-task Beta"
		await addChildInput.fill('Sub-task Beta')
		await addChildInput.press('Enter')

		await expect(page.locator('.card-modal__child').filter({ hasText: 'Sub-task Beta' }))
			.toBeVisible({ timeout: 8000 })

		// Assert Children section shows 2 items
		await expect(page.locator('.card-modal__child')).toHaveCount(2, { timeout: 5000 })

		// Assert progress shows 0 / 2
		await expect(page.locator('.card-modal__section-count'))
			.toHaveText('0 / 2', { timeout: 5000 })
	})

	test('toggle one child done via API, reload parent modal, assert progress 1/2', async ({ page }) => {
		await ncLogin(page)

		// Fetch the parent card detail to find child ids
		const parentDetail = await apiGet(`/cards/${state.parentCardId}`)
		const children = parentDetail.children ?? []
		expect(children.length).toBe(2)

		// Mark the first child done via the API (set done: true)
		const firstChild = children[0]
		await apiPatch(`/cards/${firstChild.id}`, { done: true })

		// Open the board and the parent card modal
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await expect(parentTile).toBeVisible({ timeout: 5000 })
		await parentTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Progress should now show 1 / 2
		await expect(page.locator('.card-modal__section-count'))
			.toHaveText('1 / 2', { timeout: 8000 })

		// The done child should have its done indicator active
		const doneChildItem = page.locator('.card-modal__child').filter({ hasText: firstChild.title })
		await expect(doneChildItem.locator('.card-modal__child-dot--done'))
			.toBeVisible({ timeout: 5000 })

		// Close the modal
		await page.keyboard.press('Escape')
		await page.waitForSelector('.card-modal', { state: 'hidden', timeout: 5000 }).catch(() => {})

		// The parent tile should now show a child-progress badge with 1/2
		await expect(
			page.locator('.card-tile').filter({ hasText: 'Parent Card' })
				.locator('.card-tile__children'),
		).toHaveText(/1\/2/, { timeout: 8000 })
	})

	test('reload board and assert parent tile persists child badge 1/2', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Tile badge should show 1/2 after fresh load
		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await expect(parentTile.locator('.card-tile__children'))
			.toHaveText(/1\/2/, { timeout: 8000 })

		// Open and re-verify modal progress
		await parentTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		await expect(page.locator('.card-modal__section-count'))
			.toHaveText('1 / 2', { timeout: 8000 })
		await expect(page.locator('.card-modal__child')).toHaveCount(2, { timeout: 5000 })
	})

	test('open a child card from parent modal - child shows its Parent row', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open parent modal
		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await parentTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Click the first child link
		const firstChildLink = page.locator('.card-modal__child-link').first()
		await expect(firstChildLink).toBeVisible({ timeout: 5000 })
		await firstChildLink.click()

		// Wait for the child card modal to open (URL changes to child cardId)
		await page.waitForFunction(
			() => window.location.hash.includes('/card/'),
			{ timeout: 8000 },
		)

		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// The child card modal should show the "Parent card" section (not Sub-cards)
		const parentSection = page.locator('.card-modal__parent-link')
		await expect(parentSection).toBeVisible({ timeout: 8000 })
		await expect(parentSection).toHaveText('Parent Card', { timeout: 5000 })

		// The "Add sub-card" input should NOT be present (one-level rule: a card
		// with a parent shows the Parent section instead of the Sub-cards editor).
		await expect(page.getByPlaceholder('Add a sub-card…')).toHaveCount(0)
	})

	test('detach a child - parent progress drops to 0/1', async ({ page }) => {
		await ncLogin(page)

		// Fetch fresh parent detail to find the undone child
		const parentDetail = await apiGet(`/cards/${state.parentCardId}`)
		const children = parentDetail.children ?? []
		// Find the child that is NOT done
		const undoneChild = children.find((c) => Number(c.doneAt) === 0)
		expect(undoneChild).toBeTruthy()

		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open the parent card modal
		const parentTile = page.locator('.card-tile').filter({ hasText: 'Parent Card' })
		await parentTile.click()
		await page.waitForSelector('.card-modal', { timeout: 10_000 })

		// Hover the undone child item to reveal the remove button, then click it
		const undoneChildItem = page.locator('.card-modal__child').filter({ hasText: undoneChild.title })
		await undoneChildItem.hover()
		const removeBtn = undoneChildItem.locator('.card-modal__child-remove')
		await expect(removeBtn).toBeVisible({ timeout: 3000 })
		await removeBtn.click()

		// Progress should now be 1 / 1 (only the done child remains). Since we
		// detached the undone one, 1 done out of 1 total → progress text "1 / 1".
		await expect(page.locator('.card-modal__section-count'))
			.toHaveText('1 / 1', { timeout: 8000 })

		// The list should now have 1 item
		await expect(page.locator('.card-modal__child')).toHaveCount(1, { timeout: 5000 })
	})
})

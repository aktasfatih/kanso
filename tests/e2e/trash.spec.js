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
	// Some endpoints return [] on success; handle empty body gracefully.
	const text = await r.text()
	try {
		return JSON.parse(text)
	} catch {
		return null
	}
}

async function ncLogin(page) {
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
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

// ── Helpers for opening the routed Trash page ────────────────────────────────

async function openTrashPage(page) {
	// Trash now lives in the consolidated ⋯ More overflow menu.
	await page.getByRole('button', { name: 'More' }).click()
	const trashBtn = page.getByRole('menuitem', { name: 'Deleted cards' })
	await expect(trashBtn).toBeVisible({ timeout: 8000 })
	await trashBtn.click()
	// Deep-linkable routed page.
	await expect(page).toHaveURL(/#\/board\/\d+\/trash/, { timeout: 8000 })
	await page.waitForSelector('.trash-view', { timeout: 8000 })
	// Give the trash query time to resolve.
	await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {})
}

async function backToBoard(page) {
	await page.locator('.trash-view__back').click()
	await page.waitForSelector('.board-view__header', { timeout: 8000 })
}

// ── Test suite ───────────────────────────────────────────────────────────────

test.describe('Trash', () => {
	const state = {
		boardId: 0,
		stackId: 0,
		cardId: 0,
		boardUrl: '',
	}

	test.beforeAll(async () => {
		// Tear down any prior board with the same title to keep tests hermetic.
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Trash E2E Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card.
		const board = await apiPost('/boards', { title: 'Trash E2E Test Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Trashable Card' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		// Best-effort cleanup - ignore errors so a failed board creation does not
		// leave the teardown in a broken state for the next run.
		try {
			if (state.boardId) {
				await apiDelete(`/boards/${state.boardId}`)
			}
		} catch {}
	})

	test('soft-deleted card appears in the Trash page', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Soft-delete the card via the API (existing DELETE endpoint).
		await apiDelete(`/cards/${state.cardId}`)

		// Reload so the board query reflects the deletion.
		await page.reload()
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// The card tile should no longer be visible on the board.
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Trashable Card' })
		await expect(cardTile).not.toBeVisible()

		// Open the routed Trash page.
		await openTrashPage(page)

		// The card should appear in the virtualized trash list.
		const trashItem = page.locator('.trash-view__row-title').filter({ hasText: 'Trashable Card' })
		await expect(trashItem).toBeVisible({ timeout: 8000 })
	})

	test('the Trash page is deep-linkable via its route', async ({ page }) => {
		await ncLogin(page)
		// Navigate straight to the trash URL (deep link, no board visit first).
		await page.goto(`${state.boardUrl}/trash`)
		await page.waitForSelector('.trash-view', { timeout: 12_000 })
		await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {})

		const trashItem = page.locator('.trash-view__row-title').filter({ hasText: 'Trashable Card' })
		await expect(trashItem).toBeVisible({ timeout: 8000 })
	})

	test('restoring a card removes it from Trash and returns it to the board', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// Open the routed Trash page.
		await openTrashPage(page)

		// The card must be present in the trash list.
		const trashItem = page.locator('.trash-view__row-title').filter({ hasText: 'Trashable Card' })
		await expect(trashItem).toBeVisible({ timeout: 8000 })

		// Click the Restore button on the card row.
		const cardItem = page.locator('.trash-view__row').filter({ hasText: 'Trashable Card' })
		const restoreBtn = cardItem.locator('button', { hasText: 'Restore' })
		await expect(restoreBtn).toBeVisible({ timeout: 5000 })
		await restoreBtn.click()

		// The item should disappear from the trash list (optimistic removal + invalidation).
		await expect(trashItem).not.toBeVisible({ timeout: 8000 })

		// Back to the board via the header affordance.
		await backToBoard(page)

		// The card should have reappeared on the board.
		const boardCardTile = page.locator('.card-tile').filter({ hasText: 'Trashable Card' })
		await expect(boardCardTile).toBeVisible({ timeout: 10_000 })
	})

	test('permanently deleting a card removes it from Trash and it is not recoverable via API', async ({ page }) => {
		// Re-soft-delete the card so it is in the trash again.
		await apiDelete(`/cards/${state.cardId}`)

		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// Open the routed Trash page.
		await openTrashPage(page)

		// The card must appear in the trash.
		const trashItem = page.locator('.trash-view__row-title').filter({ hasText: 'Trashable Card' })
		await expect(trashItem).toBeVisible({ timeout: 8000 })

		// Click "Delete permanently" to reveal the inline confirm.
		const cardItem = page.locator('.trash-view__row').filter({ hasText: 'Trashable Card' })
		const deleteBtn = cardItem.locator('button', { hasText: 'Delete permanently' })
		await expect(deleteBtn).toBeVisible({ timeout: 5000 })
		await deleteBtn.click()

		// The confirm row should appear.
		const confirmText = cardItem.locator('.trash-view__confirm-text')
		await expect(confirmText).toBeVisible({ timeout: 3000 })

		// Click "Yes, delete" to confirm purge.
		const confirmBtn = cardItem.locator('button', { hasText: 'Yes, delete' })
		await expect(confirmBtn).toBeVisible({ timeout: 3000 })
		await confirmBtn.click()

		// The item should disappear from the trash list.
		await expect(trashItem).not.toBeVisible({ timeout: 8000 })

		// Verify via API: GET /boards/{id}/trash must not include this card.
		const trash = await apiGet(`/boards/${state.boardId}/trash`)
		const stillInTrash = Array.isArray(trash) && trash.some((c) => c.id === state.cardId)
		expect(stillInTrash).toBe(false)

		// The card id is now gone; nullify so afterAll cleanup does not try to delete it.
		state.cardId = 0
	})
})

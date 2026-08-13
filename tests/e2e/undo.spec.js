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
	const text = await r.text()
	try {
		return JSON.parse(text)
	} catch {
		return null
	}
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
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title === 'Undo E2E Test Board') {
				await apiDelete(`/boards/${b.id}`)
			}
		}

		// Create fresh board + stack + card.
		const board = await apiPost('/boards', { title: 'Undo E2E Test Board' })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'Undo Test Stack' })
		state.stackId = stack.id
		const card = await apiPost('/cards', { stackId: stack.id, title: 'Undo Test Card' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		try {
			if (state.boardId) {
				await apiDelete(`/boards/${state.boardId}`)
			}
		} catch {}
	})

	test('deleting a card shows an Undo toast; clicking Undo restores the card', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Open the card modal by clicking the card tile.
		const cardTile = page.locator('.card-tile').filter({ hasText: 'Undo Test Card' })
		await expect(cardTile).toBeVisible({ timeout: 10_000 })
		await cardTile.click()

		// Wait for the modal to appear.
		await page.waitForSelector('.card-modal', { timeout: 8_000 })

		// Open the actions (⋯) menu inside the modal.
		const actionsMenu = page.locator('.card-modal__actions-menu')
		await expect(actionsMenu).toBeVisible({ timeout: 5_000 })
		await actionsMenu.click()

		// Click Delete in the actions dropdown - no confirm banner, just immediate delete.
		const deleteBtn = page.locator('[role="menuitem"]').filter({ hasText: 'Delete' })
		await expect(deleteBtn).toBeVisible({ timeout: 5_000 })
		await deleteBtn.click()

		// Modal should have closed.
		await expect(page.locator('.card-modal')).not.toBeVisible({ timeout: 8_000 })

		// The undo toast should appear - @nextcloud/dialogs uses toastify-js which
		// renders toasts with class .toast-undo (ToastType.UNDO).
		const undoToast = page.locator('.toast-undo')
		await expect(undoToast).toBeVisible({ timeout: 8_000 })

		// The toast should contain text indicating the card was deleted.
		await expect(undoToast).toContainText('deleted')

		// Click the Undo button inside the toast.
		const undoBtn = undoToast.locator('button').filter({ hasText: 'Undo' })
		await expect(undoBtn).toBeVisible({ timeout: 5_000 })
		await undoBtn.click()

		// The toast should dismiss.
		await expect(undoToast).not.toBeVisible({ timeout: 8_000 })

		// After undo (restore), the board query is invalidated and the card should
		// reappear on the board. Allow generous time for the network round-trip.
		const restoredTile = page.locator('.card-tile').filter({ hasText: 'Undo Test Card' })
		await expect(restoredTile).toBeVisible({ timeout: 15_000 })
	})
})

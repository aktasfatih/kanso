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

test.describe('Settings panel (right-docked drawer)', () => {
	const BOARD_TITLE = 'SettingsPanel E2E ' + Date.now()
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		// Clean up any stale boards with the same name, then create fresh fixtures
		const boards = await apiGet('/boards')
		for (const b of boards) {
			if (b.title.startsWith('SettingsPanel E2E')) {
				await apiDelete(`/boards/${b.id}`)
			}
		}
		const board = await apiPost('/boards', { title: BOARD_TITLE })
		state.boardId = board.id
		const stack = await apiPost('/stacks', { boardId: board.id, title: 'To Do' })
		await apiPost('/cards', { stackId: stack.id, title: 'First card' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		await apiDelete(`/boards/${state.boardId}`).catch(() => {})
	})

	test('panel opens anchored to the right, board remains visible and interactive, Escape closes', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)

		// Wait for the board to fully render (card tile is visible)
		const card = page.locator('.card-tile', { hasText: 'First card' })
		await expect(card).toBeVisible({ timeout: 20_000 })

		// Open settings panel via gear button
		await page.getByRole('button', { name: /board settings/i }).click()

		// Panel must be visible
		const panel = page.locator('.board-settings-panel')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		// ── Positioning assertions ────────────────────────────────────────────
		const viewport = page.viewportSize()
		const panelBox = await panel.boundingBox()
		expect(panelBox).not.toBeNull()

		// Right edge of panel must be at (or within 2px of) the right edge of viewport
		expect(panelBox.x + panelBox.width).toBeGreaterThanOrEqual(viewport.width - 2)

		// Left edge of panel must be well right of the viewport center
		// (panel width ~380px; viewport is typically ≥1280px in CI)
		expect(panelBox.x).toBeGreaterThan(viewport.width / 2)

		// ── Board card still visible & interactive while panel is open ────────
		// The card is in the left column (not under the right-docked panel) and
		// must remain visible with pointer events (panel must not block it).
		await expect(card).toBeVisible()
		const pointerEvents = await card.evaluate((el) => getComputedStyle(el).pointerEvents)
		expect(pointerEvents).not.toBe('none')
		// The card is not covered by the panel: its left edge sits left of the panel.
		const cardBox = await card.boundingBox()
		expect(cardBox.x).toBeLessThan(panelBox.x)

		// ── Escape key closes the panel ───────────────────────────────────────
		await page.keyboard.press('Escape')
		await expect(panel).not.toBeVisible({ timeout: 3_000 })
	})

	test('close button (×) inside panel header dismisses the panel', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)

		await page.locator('.card-tile').first().waitFor({ state: 'visible', timeout: 20_000 })

		// Open panel
		await page.getByRole('button', { name: /board settings/i }).click()
		const panel = page.locator('.board-settings-panel')
		await expect(panel).toBeVisible({ timeout: 5_000 })

		// Click the × close button inside the panel header
		await page.locator('.board-settings-panel__close').click()
		await expect(panel).not.toBeVisible({ timeout: 3_000 })
	})

	test('gear button toggles the panel (second click closes)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)

		await page.locator('.card-tile').first().waitFor({ state: 'visible', timeout: 20_000 })

		const gearBtn = page.getByRole('button', { name: /board settings/i })
		const panel = page.locator('.board-settings-panel')

		// First click opens
		await gearBtn.click()
		await expect(panel).toBeVisible({ timeout: 5_000 })

		// Second click closes (toggle behavior)
		await gearBtn.click()
		await expect(panel).not.toBeVisible({ timeout: 3_000 })
	})
})

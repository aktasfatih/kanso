// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function apiSend(method, path, body) {
	const r = await fetch(API + path, {
		method,
		headers: { ...HEADERS, Authorization: AUTH },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!r.ok) throw new Error(`${method} ${path} → ${r.status}: ${await r.text()}`)
	return method === 'DELETE' ? null : r.json()
}

async function ncLogin(page) {
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

/**
 * Open the ⋯ NcActions menu for the first column on the page and return the
 * teleported dialog locator. All tests in this file have a single column.
 *
 * NcActions teleports its menu panel to <body> as a [role="dialog"]. We wait
 * for it to appear and return a locator scoped to that dialog so callers can
 * find items without accidentally hitting other page elements.
 */
async function openColumnMenu(page) {
	// The NcActions trigger button lives inside .stack-column__actions
	await page.locator('.stack-column__actions button').first().click()
	// Wait for the teleported menu dialog to appear
	const dialog = page.locator('[role="dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 6_000 })
	return dialog
}

test.describe('Column controls (role + WIP limit)', () => {
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await apiSend('POST', '/boards', { title: 'Column Controls E2E' })
		state.boardId = board.id
		await apiSend('POST', '/stacks', { boardId: board.id, title: 'Control Column' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	// ── Test: Set status to "Done" via ⋯ menu → role chip shows "Done" ─────────
	test('Set status to "Done" via ⋯ menu → role chip shows "Done"', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		const dialog = await openColumnMenu(page)

		// NcActionRadio renders as <input type="radio"> visually hidden behind an
		// SVG icon. The <li> wrapper is the clickable row; clicking the label text
		// avoids the pointer-events blocker on the SVG.
		// The listitem contains a radio + a text node with the role label.
		const doneItem = dialog.locator('li', { hasText: /^done$/i })
		await expect(doneItem).toBeVisible({ timeout: 6_000 })
		await doneItem.click()

		// Role chip should now show "Done"
		const chip = page.locator('.stack-column__role-chip', { hasText: 'Done' })
		await expect(chip).toBeVisible({ timeout: 8_000 })

		// Persisted: reload and still shows "Done"
		await page.reload()
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })
		await expect(page.locator('.stack-column__role-chip', { hasText: 'Done' })).toBeVisible({ timeout: 8_000 })
	})

	// ── Test: Set WIP limit via ⋯ menu → badge shows limit ──────────────────────
	test('Set WIP limit via ⋯ menu → badge shows limit', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		const dialog = await openColumnMenu(page)

		// NcActionInput renders as role="spinbutton" with accessible name "WIP limit"
		const wipInput = dialog.getByRole('spinbutton', { name: /wip limit/i })
		await expect(wipInput).toBeVisible({ timeout: 6_000 })
		await wipInput.fill('3')
		// Submit via the NcActionInput's submit button inside the dialog
		await dialog.getByRole('button', { name: /^submit$/i }).click()

		// Close menu if still open
		await page.keyboard.press('Escape')

		// WIP badge should reflect the new limit (e.g. "0 / 3")
		const badge = page.locator('.stack-column__badge', { hasText: '/ 3' })
		await expect(badge).toBeVisible({ timeout: 8_000 })

		// Persisted: reload and still shows limit
		await page.reload()
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })
		await expect(page.locator('.stack-column__badge', { hasText: '/ 3' })).toBeVisible({ timeout: 8_000 })
	})

	// ── Test: Clear WIP limit (set to 0) removes the "/ N" from badge ───────────
	test('Clear WIP limit (set to 0) removes the "/ N" from badge', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		const dialog = await openColumnMenu(page)

		const wipInput = dialog.getByRole('spinbutton', { name: /wip limit/i })
		await expect(wipInput).toBeVisible({ timeout: 6_000 })
		await wipInput.fill('0')
		await dialog.getByRole('button', { name: /^submit$/i }).click()

		await page.keyboard.press('Escape')

		// Badge should no longer contain "/ N"
		await expect(page.locator('.stack-column__badge', { hasText: '/ 3' })).toHaveCount(0, { timeout: 8_000 })
	})

	// ── Test: Rename action in ⋯ menu triggers inline rename ────────────────────
	// Run LAST so earlier tests are not affected by the title mutation.
	test('Rename action in ⋯ menu triggers inline rename', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		const dialog = await openColumnMenu(page)

		// Verify the Rename column action exists in the menu.
		const renameItem = dialog.locator('li', { hasText: /rename column/i })
		await expect(renameItem).toBeVisible({ timeout: 6_000 })
		const renameBtn = renameItem.locator('button')
		await renameBtn.click()

		// The app guards the rename field against the closing menu's focus-trap
		// blur (StackColumn.onTitleBlur), so the input stays open on its own.
		const input = page.locator('.stack-column__title-input')
		await expect(input).toBeVisible({ timeout: 4_000 })
		await input.fill('Renamed via Menu')
		await input.press('Enter')

		await expect(page.locator('.stack-column__title', { hasText: 'Renamed via Menu' })).toBeVisible({ timeout: 6_000 })
	})
})

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')

async function api(method, path, body) {
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

// Board background (#3528): a curated preset gradient rendered behind the board
// view. Presets only (no free-form CSS). This drives the palette from the UI and
// asserts the chosen background actually applies to the board view.
test.describe('Board background preset (#3528)', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Background E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('picking a background preset applies it to the board view', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		const boardView = page.locator('.board-view')
		// No background to start with.
		await expect(boardView).not.toHaveClass(/board-view--has-background/)

		await page.getByRole('button', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		await expect(page.locator('#bs-pane-general')).toBeVisible({ timeout: 8_000 })

		// Pick the "ocean" preset from the curated palette.
		await page.locator('[data-test="board-bg-ocean"]').click()

		// The board view now carries the background class and the CSS variable.
		await expect(boardView).toHaveClass(/board-view--has-background/, { timeout: 8_000 })
		const bg = await boardView.evaluate((el) => getComputedStyle(el).getPropertyValue('--board-background'))
		expect(bg).toContain('linear-gradient')

		// It persists across a reload (server is the source of truth).
		await page.reload()
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })
		await expect(page.locator('.board-view')).toHaveClass(/board-view--has-background/, { timeout: 8_000 })

		// Clearing it removes the background again.
		await page.getByRole('button', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /general/i }).click()
		await expect(page.locator('#bs-pane-general')).toBeVisible({ timeout: 8_000 })
		await page.locator('[data-test="board-bg-none"]').click()
		await expect(page.locator('.board-view')).not.toHaveClass(/board-view--has-background/, { timeout: 8_000 })
	})
})

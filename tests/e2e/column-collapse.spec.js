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

// ─────────────────────────────────────────────────────────────────────────────
// Column collapse / fold to a rail (#3677)
//
// Per-user, view-only collapse: a collapsed column shrinks to a slim rail that
// shows its title + card count, hiding the cards. State persists across reload
// (localStorage). Verifies the full lifecycle: collapse → cards hidden + rail
// count → reload still collapsed → expand → cards back.
// ─────────────────────────────────────────────────────────────────────────────
test.describe('Column collapse (#3677)', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await apiSend('POST', '/boards', { title: 'Column Collapse E2E' })
		state.boardId = board.id
		const stack = await apiSend('POST', '/stacks', { boardId: board.id, title: 'Foldable' })
		state.stackId = stack.id
		// Two cards so we can assert the count on the rail and that they hide.
		await apiSend('POST', '/cards', { stackId: stack.id, title: 'Alpha card' })
		await apiSend('POST', '/cards', { stackId: stack.id, title: 'Beta card' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('collapse hides cards + shows rail count, persists across reload, expands back', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 15_000 })

		// Cards visible in the expanded state.
		await expect(page.locator('.card-tile', { hasText: 'Alpha card' })).toBeVisible({ timeout: 8_000 })

		// Collapse via the header toggle button.
		await page.locator('.stack-column__collapse-btn').first().click()

		// The rail appears with the column title + card count; cards are hidden.
		const rail = page.locator('.stack-column__rail')
		await expect(rail).toBeVisible({ timeout: 6_000 })
		await expect(rail.locator('.stack-column__rail-title')).toHaveText('Foldable')
		await expect(rail.locator('.stack-column__rail-count')).toHaveText('2')
		await expect(page.locator('.card-tile', { hasText: 'Alpha card' })).toBeHidden({ timeout: 6_000 })
		// Full header (with composer) is hidden while collapsed.
		await expect(page.locator('.card-composer__input')).toBeHidden({ timeout: 6_000 })

		// Persisted per user: reload → still collapsed.
		await page.reload()
		await page.waitForSelector('.stack-column__rail', { timeout: 15_000 })
		await expect(page.locator('.stack-column__rail')).toBeVisible({ timeout: 6_000 })
		await expect(page.locator('.card-tile', { hasText: 'Alpha card' })).toBeHidden({ timeout: 6_000 })

		// Expand by clicking the rail → cards come back, rail gone.
		await page.locator('.stack-column__rail').click()
		await expect(page.locator('.stack-column__rail')).toHaveCount(0, { timeout: 6_000 })
		await expect(page.locator('.card-tile', { hasText: 'Alpha card' })).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.card-composer__input').first()).toBeVisible({ timeout: 6_000 })
	})
})

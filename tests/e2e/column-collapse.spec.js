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
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
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

// Regression (#3677): a collapsed rail's height must not follow its (hidden)
// card count — a 1-card column and a full column collapse to the SAME
// full-height rail. Previously the rail filled a content-height column, so
// short stacks collapsed to stubs while a full stack stayed tall.
test.describe('Column collapse — equal-height rails (#3677)', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await apiSend('POST', '/boards', { title: 'Collapse Height E2E' })
		state.boardId = board.id
		const long = await apiSend('POST', '/stacks', { boardId: board.id, title: 'Long Stack' })
		const a = await apiSend('POST', '/stacks', { boardId: board.id, title: 'One A' })
		const b = await apiSend('POST', '/stacks', { boardId: board.id, title: 'One B' })
		for (let i = 0; i < 25; i++) await apiSend('POST', '/cards', { stackId: long.id, title: `Card ${i}` })
		await apiSend('POST', '/cards', { stackId: a.id, title: 'Only A' })
		await apiSend('POST', '/cards', { stackId: b.id, title: 'Only B' })
	})

	test.afterAll(async () => {
		if (state.boardId) await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('a full stack and a 1-card stack collapse to equal-height rails', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.stack-column', { timeout: 20_000 })
		await page.waitForTimeout(600)

		// Collapse all three (always the leftmost still-expanded column).
		for (let i = 0; i < 3; i++) {
			await page.locator('.stack-column:not(.stack-column--collapsed) .stack-column__collapse-btn').first().click()
			await page.waitForTimeout(300)
		}

		const heights = await page.$$eval('.stack-column--collapsed',
			els => els.map(e => Math.round(e.getBoundingClientRect().height)))
		expect(heights.length).toBe(3)
		// All rails the same height (full board height), not card-count-driven stubs.
		expect(Math.max(...heights) - Math.min(...heights)).toBeLessThanOrEqual(2)
		expect(Math.min(...heights)).toBeGreaterThan(300)
	})
})

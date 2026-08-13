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

// #3521 — default board on start.
test.describe('Default board on start', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		state.boardId = (await api('POST', '/boards', { title: 'Default-Board E2E' })).id
		await api('POST', '/stacks', { boardId: state.boardId, title: 'To Do' })
	})

	test.afterAll(async () => {
		await api('PUT', '/settings', { defaultBoardId: null }).catch(() => {})
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('setting persists and the app opens directly to the chosen board', async ({ page }) => {
		// Set the preference via the API (the UI toggle lives in board settings).
		const res = await api('PUT', '/settings', { defaultBoardId: state.boardId })
		expect(res.defaultBoardId).toBe(state.boardId)
		expect((await api('GET', '/settings')).defaultBoardId).toBe(state.boardId)

		// Opening the app root redirects to the default board.
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso`)
		await page.waitForURL(
			(url) => url.hash.includes(`/board/${state.boardId}`),
			{ timeout: 15_000 },
		)
		await expect(page.locator('.board-view__header')).toBeVisible({ timeout: 10_000 })

		// Clearing the preference restores the board-list landing.
		expect((await api('PUT', '/settings', { defaultBoardId: null })).defaultBoardId).toBeNull()
	})
})

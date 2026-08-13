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

test.describe('Boards in the left navigation', () => {
	const state = { boardId: 0, title: 'Nav Board ' + Math.floor(Date.now() / 1000) }

	test.beforeAll(async () => {
		const board = await apiSend('POST', '/boards', { title: state.title, color: '9b59b6' })
		state.boardId = board.id
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('the board is listed in the nav and navigates when clicked', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.app-navigation', { timeout: 15_000 })

		// The board appears as a nav entry under Boards.
		const item = page.locator('.app-navigation .app-navigation-entry-link', { hasText: state.title })
		await expect(item.first()).toBeVisible({ timeout: 10_000 })

		await item.first().click()
		await expect(page).toHaveURL(new RegExp(`#/board/${state.boardId}`), { timeout: 10_000 })
		await page.waitForSelector('.board-view__header', { timeout: 10_000 })
	})
})

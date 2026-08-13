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

test.describe('Column rename', () => {
	const state = { boardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await apiSend('POST', '/boards', { title: 'Column Rename E2E' })
		state.boardId = board.id
		await apiSend('POST', '/stacks', { boardId: board.id, title: 'Original Column' })
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) {
			await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
		}
	})

	test('clicking a column title renames it inline', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 12_000 })

		await page.locator('.stack-column__title', { hasText: 'Original Column' }).click()

		const input = page.locator('.stack-column__title-input')
		await expect(input).toBeVisible({ timeout: 4_000 })
		await input.fill('Renamed Column')
		await input.press('Enter')

		await expect(page.locator('.stack-column__title', { hasText: 'Renamed Column' })).toBeVisible({ timeout: 6_000 })

		// Persisted: a reload still shows the new name.
		await page.reload()
		await page.waitForSelector('.stack-column__header', { timeout: 12_000 })
		await expect(page.locator('.stack-column__title', { hasText: 'Renamed Column' })).toBeVisible({ timeout: 8_000 })
	})

	test('Escape cancels the rename', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.stack-column__header', { timeout: 12_000 })

		await page.locator('.stack-column__title', { hasText: 'Renamed Column' }).click()
		const input = page.locator('.stack-column__title-input')
		await expect(input).toBeVisible({ timeout: 4_000 })
		await input.fill('Should Not Stick')
		await input.press('Escape')

		await expect(page.locator('.stack-column__title', { hasText: 'Renamed Column' })).toBeVisible({ timeout: 6_000 })
		await expect(page.locator('.stack-column__title', { hasText: 'Should Not Stick' })).toHaveCount(0)
	})
})

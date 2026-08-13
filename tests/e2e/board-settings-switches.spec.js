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

// Regression guard: the public-link (#3531) and calendar-feed (#3541) enable
// switches used the wrong @nextcloud/vue binding (:checked / @update:checked),
// so NcCheckboxRadioSwitch's update:modelValue never fired and clicking the
// switch did nothing. The API worked (so the API-level specs passed), but the
// UI toggle was dead. This drives the actual switches from the UI.
test.describe('Board settings enable switches (public link + calendar feed)', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Switches E2E ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('the calendar-feed and public-link switches enable from the UI', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Board settings now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: /automation/i }).click()
		await expect(page.locator('#bs-pane-automation')).toBeVisible({ timeout: 8_000 })

		// --- Calendar feed (#3541) ---
		await page.getByRole('button', { name: /Calendar feed/i }).click() // expand the group
		const calBody = page.locator('#bs-automation-calendar-feed')
		await expect(calBody).toBeVisible()
		// Flip the switch — before the fix this was a no-op.
		await calBody.getByText('Enable calendar feed').click()
		// Enabled → the "Feed active" badge appears (only rendered when enabled).
		await expect(page.getByText('Feed active')).toBeVisible({ timeout: 8_000 })

		// --- Public link (#3531) ---
		await page.getByRole('button', { name: /Public link/i }).click() // expand the group
		const pubBody = page.locator('#bs-automation-public-link')
		await expect(pubBody).toBeVisible()
		await pubBody.getByText('Enable public link').click()
		await expect(page.getByText('Link active')).toBeVisible({ timeout: 8_000 })
	})
})

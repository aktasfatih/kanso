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

test.describe('Responsive board header', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'Responsive ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
		await api('POST', '/stacks', { boardId: board.id, title: 'To do' })
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('view / sort / group / density all live under one Display popover', async ({ page }) => {
		await page.setViewportSize({ width: 1500, height: 900 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// One Display control; the old standalone view/sort menus are gone.
		await expect(page.locator('.board-view__display-menu')).toBeVisible({ timeout: 8_000 })
		await expect(page.locator('.board-view__view-menu')).toHaveCount(0)
		await expect(page.locator('.board-view__sort-menu')).toHaveCount(0)

		// It holds View + Sort (+ Group + Density).
		await page.locator('.board-view__display-menu button').first().click()
		await expect(page.getByRole('menuitemradio', { name: 'Timeline' })).toBeVisible({ timeout: 8_000 })
		await expect(page.getByRole('menuitemradio', { name: 'Manual', exact: true })).toBeVisible()
	})

	test('narrow: header stays compact and Display remains reachable; nothing off-screen', async ({ page }) => {
		// Phone-width → the NC sidebar collapses and the header is compact.
		await page.setViewportSize({ width: 480, height: 900 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Search, filter, Display and ⋯ all remain reachable in the bar.
		await expect(page.locator('.board-view__search')).toBeVisible()
		await expect(page.locator('.board-filter-bar__filter')).toBeVisible()
		await expect(page.getByRole('button', { name: 'More' })).toBeVisible()
		const display = page.locator('.board-view__display-menu button').first()
		await expect(display).toBeVisible()

		// Display still drives the view: switch to List from it.
		await display.click()
		await page.getByRole('menuitemradio', { name: 'List', exact: true }).click()
		await expect(page.locator('.board-list-table')).toBeVisible({ timeout: 8_000 })
	})
})

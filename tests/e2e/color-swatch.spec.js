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

// #3467: the label-settings colour-pick swatch must be a perfect circle, not an
// oval - assert the rendered button is geometrically square.
test.describe('Colour-pick swatch shape', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await apiSend('POST', '/boards', { title: 'Swatch ' + Math.floor(Date.now() / 1000) })
		state.boardId = board.id
	})

	test.afterAll(async () => {
		if (state.boardId) await apiSend('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('the label colour swatch renders square (circle, not oval)', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open board settings → Labels tab. Board settings now lives in the
		// consolidated ⋯ More overflow menu, so open that first.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Board settings' }).click()
		await page.waitForSelector('.label-settings__swatch', { timeout: 10_000 })

		const box = await page.locator('.label-settings__swatch').first().boundingBox()
		expect(box).toBeTruthy()
		// Square within a sub-pixel tolerance → a circle once border-radius:50% applies.
		expect(Math.abs(box.width - box.height)).toBeLessThanOrEqual(1)
	})
})

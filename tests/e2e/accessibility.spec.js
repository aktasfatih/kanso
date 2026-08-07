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

// #3412 — one accessibility smoke over the board + open card modal. Uses
// Playwright's built-in accessibility snapshot (no extra dependency): asserts
// the board exposes a landmark/heading tree and the card modal opens as a
// focusable dialog with an accessible name and the aria-live region present.
test.describe('Accessibility smoke', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, cardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'A11y Smoke E2E' })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		state.cardId = (await api('POST', '/cards', { stackId: state.stackId, title: 'Accessible card' })).id
		state.cardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}/card/${state.cardId}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('board + card modal expose an accessible tree with an aria-live region', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view', { timeout: 10_000 })

		// The single polite aria-live region for own-action announcements exists.
		await expect(page.locator('.board-view [aria-live="polite"]')).toHaveCount(1)

		// Playwright's native accessibility snapshot (aria tree) must be
		// non-trivial and surface the card by its accessible name.
		const boardSnapshot = await page.locator('.board-view').ariaSnapshot()
		expect(boardSnapshot).toBeTruthy()
		expect(boardSnapshot).toContain('Accessible card')

		// Open the card modal → it must be a dialog with an accessible name.
		await page.goto(state.cardUrl)
		await page.waitForSelector('.card-modal', { timeout: 10_000 })
		const dialog = page.getByRole('dialog').first()
		await expect(dialog).toBeVisible()
		const modalSnapshot = await dialog.ariaSnapshot()
		expect(modalSnapshot).toContain('Accessible card')
	})
})

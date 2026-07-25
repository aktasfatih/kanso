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

test.describe('Board List view (#3444)', () => {
	const state = { boardId: 0, title: 'List View ' + Math.floor(Date.now() / 1000), cardTitle: 'List row card' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: state.title })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'To do' })
		await api('POST', '/cards', { stackId: stack.id, title: state.cardTitle })
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('switches to List, renders card rows, opens a card, and switches back', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open the View menu and pick List.
		await page.locator('.board-view__view-menu button').first().click()
		await page.getByText('List', { exact: true }).click()

		// The card renders as a list row.
		const row = page.locator('.board-list-row', { hasText: state.cardTitle })
		await expect(row).toBeVisible({ timeout: 8_000 })
		// The Board columns are hidden.
		await expect(page.locator('.board-view__stacks-wrap')).toBeHidden()

		// Clicking the row opens the card modal.
		await row.click()
		await expect(page).toHaveURL(new RegExp(`/board/${state.boardId}/card/`), { timeout: 8_000 })
		await expect(page.locator('.card-modal')).toBeVisible({ timeout: 10_000 })

		// Close the modal, switch back to Board.
		await page.keyboard.press('Escape')
		await page.locator('.board-view__view-menu button').first().click()
		await page.getByText('Board', { exact: true }).click()
		await expect(page.locator('.board-view__stacks-wrap')).toBeVisible({ timeout: 8_000 })
	})
})

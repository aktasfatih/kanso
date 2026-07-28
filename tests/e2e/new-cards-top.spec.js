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

// #3517 — new cards on top (per-board toggle).
test.describe('New cards on top', () => {
	const state = { boardId: 0, stackId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: 'New-On-Top E2E' })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })).id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	async function stackOrder() {
		const board = await api('GET', `/boards/${state.boardId}`)
		return board.cards
			.filter((c) => c.stackId === state.stackId && !c.archived)
			.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : 0))
			.map((c) => c.title)
	}

	test('the board toggle makes new cards land at the top', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForSelector('.board-view__header', { timeout: 15_000 })

		// Open board settings → General tab → turn on "Add new cards to the top".
		await page.getByRole('button', { name: /board settings/i }).click()
		await page.getByRole('tab', { name: 'General' }).click()
		await page.getByText('Add new cards to the top of a column').click()

		// Flag persisted (board fields are nested under `.board`).
		await expect
			.poll(async () => (await api('GET', `/boards/${state.boardId}`)).board.newCardsOnTop, { timeout: 8_000 })
			.toBe(true)

		// New cards (via the real create path) now land at the top: B above A.
		await api('POST', '/cards', { stackId: state.stackId, title: 'Card A' })
		await api('POST', '/cards', { stackId: state.stackId, title: 'Card B' })
		expect(await stackOrder()).toEqual(['Card B', 'Card A'])
	})
})

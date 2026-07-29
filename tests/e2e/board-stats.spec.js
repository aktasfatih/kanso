// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Board analytics e2e (#3448): a board with a few cards across two stacks and
// mixed priorities. The header analytics button opens the CSS-bar stats page,
// which shows the "Cards by stack" distribution and the at-a-glance counters.

import { test, expect } from '@playwright/test'

const BASE = 'http://localhost:8891'
const USER = 'admin'
const PASS = 'admin'
const API = BASE + '/index.php/apps/kanso/api'
const HEADERS = { 'OCS-APIREQUEST': 'true', 'Content-Type': 'application/json' }
const AUTH = 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64')

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

test.describe('Board analytics', () => {
	const state = { boardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: `Analytics E2E ${Date.now()}` })
		state.boardId = board.id
		const todo = await api('POST', '/stacks', { boardId: board.id, title: 'To Do' })
		const doing = await api('POST', '/stacks', { boardId: board.id, title: 'Doing' })
		const c1 = await api('POST', '/cards', { stackId: todo.id, title: 'Card one' })
		await api('POST', '/cards', { stackId: todo.id, title: 'Card two' })
		await api('POST', '/cards', { stackId: doing.id, title: 'Card three' })
		await api('PATCH', `/cards/${c1.id}`, { priority: 4 })
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('the header analytics button opens the stats page with distributions', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/board/${state.boardId}`)
		await page.waitForSelector('.board-view__header', { timeout: 10_000 })

		await page.locator('.board-view__analytics-btn').click()

		await expect(page).toHaveURL(new RegExp(`#/board/${state.boardId}/stats`))
		const view = page.locator('.board-stats__body')
		await expect(view).toBeVisible({ timeout: 10_000 })

		// The "Cards by stack" distribution renders with humanized stack titles.
		await expect(page.getByText('Cards by stack')).toBeVisible()
		await expect(view).toContainText('To Do')
		await expect(view).toContainText('Doing')

		// At-a-glance counters render (Overdue is always present).
		await expect(page.getByText('Overdue', { exact: true })).toBeVisible()

		// At least one distribution bar rendered.
		expect(await page.locator('.board-stats__bar-row').count()).toBeGreaterThanOrEqual(2)
	})

	test('the stats API returns board-scoped aggregates', async () => {
		const stats = await api('GET', `/boards/${state.boardId}/stats`)
		// Three cards across two stacks.
		const total = stats.byStack.reduce((n, r) => n + r.count, 0)
		expect(total).toBe(3)
		expect(stats.byStack.length).toBe(2)
		// One urgent card by priority.
		expect(stats.byPriority.some((r) => r.priority === 4 && r.count === 1)).toBe(true)
		// Estimate panels null on a board with no estimate scale.
		expect(stats.estimateByStack).toBeNull()
	})
})

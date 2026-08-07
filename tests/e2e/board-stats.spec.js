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

		// Board analytics now lives in the consolidated ⋯ More overflow menu.
		await page.getByRole('button', { name: 'More' }).click()
		await page.getByRole('menuitem', { name: 'Board analytics' }).click()

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

		// Velocity + cycle-time flow panels render (present even with no
		// completions — velocity shows the 0/week rolling average, cycle time
		// shows its neutral no-data state).
		await expect(page.getByText('Velocity — completed per week')).toBeVisible()
		await expect(page.getByText('Cards / week (avg)')).toBeVisible()
		// Flow window is week-aligned (28d), rendered from the DTO's windowDays.
		await expect(page.getByText(/Cycle time — creation to done \(28d\)/)).toBeVisible()
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
		// Velocity + cycle-time flow metrics are always present. No cards done ⇒
		// zero rolling average, flat trend, null points (no numeric scale), and
		// an empty cycle-time sample.
		expect(stats.velocity.cardsPerWeek).toBe(0)
		expect(stats.velocity.cardsTrend).toBe('flat')
		expect(stats.velocity.pointsPerWeek).toBeNull()
		expect(Array.isArray(stats.velocity.weekly)).toBe(true)
		// Velocity and cycle time share one week-aligned window.
		expect(stats.velocity.windowDays).toBe(stats.cycleTime.windowDays)
		expect(stats.cycleTime.sampleSize).toBe(0)
		expect(stats.cycleTime.medianDays).toBeNull()
	})
})

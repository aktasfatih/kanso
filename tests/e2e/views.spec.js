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

test.describe('Cross-board Views (#3815)', () => {
	const state = { boardA: 0, boardB: 0, cardA: '', cardB: '', cardAId: 0, cardBId: 0, labelA: 0, labelB: 0, viewId: '' }

	test.beforeAll(async () => {
		const stamp = Math.floor(Date.now() / 1000)
		state.cardA = 'ViewsA ' + stamp
		state.cardB = 'ViewsB ' + stamp

		// Two boards, one card each, each tagged with a per-board label. The saved
		// View filters to those two labels so it narrows to EXACTLY these two cards
		// regardless of how much other data lives in the dev DB - the list stays
		// small and deterministic (no virtualization off-screen flake).
		const a = await api('POST', '/boards', { title: 'ViewsBoardA ' + stamp })
		state.boardA = a.id
		state.labelA = (await api('POST', '/labels', { boardId: a.id, title: 'vlabelA ' + stamp, color: 'ff0000' })).id
		const stackA = (await api('POST', '/stacks', { boardId: a.id, title: 'To do' })).id
		state.cardAId = (await api('POST', '/cards', { stackId: stackA, title: state.cardA })).id
		await api('PUT', `/cards/${state.cardAId}/labels/${state.labelA}`)

		const b = await api('POST', '/boards', { title: 'ViewsBoardB ' + stamp })
		state.boardB = b.id
		state.labelB = (await api('POST', '/labels', { boardId: b.id, title: 'vlabelB ' + stamp, color: '00ff00' })).id
		const stackB = (await api('POST', '/stacks', { boardId: b.id, title: 'To do' })).id
		state.cardBId = (await api('POST', '/cards', { stackId: stackB, title: state.cardB })).id
		await api('PUT', `/cards/${state.cardBId}/labels/${state.labelB}`)
	})

	test.afterAll(async () => {
		if (state.viewId) await api('DELETE', `/views/${state.viewId}`).catch(() => {})
		if (state.boardA) await api('DELETE', `/boards/${state.boardA}`).catch(() => {})
		if (state.boardB) await api('DELETE', `/boards/${state.boardB}`).catch(() => {})
	})

	test('the cross-board feed returns cards from every readable board', async () => {
		const cards = await api('GET', '/views/cards')
		const titles = cards.map((c) => c.title)
		expect(titles).toContain(state.cardA)
		expect(titles).toContain(state.cardB)
		// Each card carries its board identity for grouping + deep-link.
		const rowA = cards.find((c) => c.title === state.cardA)
		expect(rowA.boardId).toBe(state.boardA)
		expect(rowA.boardTitle).toBeTruthy()
	})

	test('create a View → it appears in the nav → opening shows both boards\' cards (List)', async ({ page }) => {
		// Persist a View spanning both boards, filtered to the two per-board labels
		// so it resolves to EXACTLY the two test cards, grouped by board so each
		// board's row shows as its own List group.
		const created = await api('PUT', '/views', {
			name: 'Views spec ' + Math.floor(Date.now() / 1000),
			filter: { labels: [state.labelA, state.labelB] },
			groupBy: 'board',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]
		state.viewId = view.id
		expect(view.id).toBeTruthy()

		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)

		// The View appears in the left nav (Views section) and is clickable.
		const navItem = page.locator('.app-navigation a', { hasText: view.name }).first()
		await expect(navItem).toBeVisible({ timeout: 15_000 })
		await navItem.click()

		// Opening it lands on the View surface and lists BOTH boards' cards.
		await expect(page).toHaveURL(new RegExp(`/views/${view.id}`))
		await expect(page.locator('.board-list-row__title', { hasText: state.cardA })).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.board-list-row__title', { hasText: state.cardB })).toBeVisible({ timeout: 15_000 })

		// Both board group headers render (grouped by board).
		await expect(page.locator('.board-list-group__title', { hasText: /ViewsBoardA/ })).toBeVisible()
		await expect(page.locator('.board-list-group__title', { hasText: /ViewsBoardB/ })).toBeVisible()
	})
})

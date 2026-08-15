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

test.describe('Cross-board saved Views (#3815)', () => {
	const tag = Math.floor(Date.now() / 1000)
	const state = {
		boardAId: 0,
		boardBId: 0,
		labelId: 0,
		matchAId: 0, // board A, carries the label → passes the View
		matchBId: 0, // board B, carries the label → passes the View (cross-board proof)
		otherId: 0, // board A, no label → filtered out
		viewName: 'Backend ' + tag,
	}

	test.beforeAll(async () => {
		// Clean any View left by a previous run (Views are per-user config).
		const existing = await api('GET', '/views')
		for (const v of existing.views ?? []) {
			if (String(v.name).startsWith('Backend ')) await api('DELETE', `/views/${encodeURIComponent(v.name)}`).catch(() => {})
		}

		const boardA = await api('POST', '/boards', { title: 'ViewsA ' + tag })
		state.boardAId = boardA.id
		const stackA = await api('POST', '/stacks', { boardId: boardA.id, title: 'To do' })
		const label = await api('POST', '/labels', { boardId: boardA.id, title: 'Backend', color: 'e74c3c' })
		state.labelId = label.id

		const cA = await api('POST', '/cards', { stackId: stackA.id, title: 'Match A ' + tag })
		await api('PUT', `/cards/${cA.id}/labels/${state.labelId}`)
		state.matchAId = cA.id

		const other = await api('POST', '/cards', { stackId: stackA.id, title: 'Other A ' + tag })
		state.otherId = other.id

		// A SECOND board with its own label of the same title — the cross-board
		// proof: a View by "Backend" must surface the labelled card from BOTH boards.
		const boardB = await api('POST', '/boards', { title: 'ViewsB ' + tag })
		state.boardBId = boardB.id
		const stackB = await api('POST', '/stacks', { boardId: boardB.id, title: 'To do' })
		const labelB = await api('POST', '/labels', { boardId: boardB.id, title: 'Backend', color: 'e74c3c' })
		const cB = await api('POST', '/cards', { stackId: stackB.id, title: 'Match B ' + tag })
		await api('PUT', `/cards/${cB.id}/labels/${labelB.id}`)
		state.matchBId = cB.id
	})

	test.afterAll(async () => {
		await api('DELETE', `/views/${encodeURIComponent(state.viewName)}`).catch(() => {})
		if (state.boardAId) await api('DELETE', `/boards/${state.boardAId}`).catch(() => {})
		if (state.boardBId) await api('DELETE', `/boards/${state.boardBId}`).catch(() => {})
	})

	test('create a View from the filter bar → it appears in the nav → opening it lists matching cross-board cards', async ({ page }) => {
		const consoleErrors = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') consoleErrors.push(msg.text())
		})

		await ncLogin(page)

		// Open a fresh View surface (the nav "New view" target).
		await page.goto(`${BASE}/index.php/apps/kanso#/views/__new__`)
		await page.waitForSelector('.views-view', { timeout: 15_000 })

		// All labelled AND unlabelled cards are present with no filter (both boards).
		await expect(page.locator('.views-view__row-title', { hasText: 'Match A ' + tag })).toHaveCount(1)
		await expect(page.locator('.views-view__row-title', { hasText: 'Match B ' + tag })).toHaveCount(1)
		await expect(page.locator('.views-view__row-title', { hasText: 'Other A ' + tag })).toHaveCount(1)

		// ── Apply a "Backend" label filter via the reused drill-in filter bar ─────
		await page.locator('.board-filter-bar__filter button').first().click()
		await page.locator('.board-filter-bar__dim-row[data-dim="labels"]').click()
		await page.locator('.board-filter-bar__opt-text', { hasText: 'Backend' }).first().click()
		await page.keyboard.press('Escape')

		// Only the labelled cards remain — from BOTH boards (cross-board filter).
		await expect(page.locator('.views-view__row-title', { hasText: 'Match A ' + tag })).toHaveCount(1)
		await expect(page.locator('.views-view__row-title', { hasText: 'Match B ' + tag })).toHaveCount(1)
		await expect(page.locator('.views-view__row-title', { hasText: 'Other A ' + tag })).toHaveCount(0)

		// ── Save it as a named View (same save UI as board saved-filters) ─────────
		await page.locator('.board-filter-bar__filter button').first().click()
		const nameInput = page.getByPlaceholder('View name')
		await nameInput.fill(state.viewName)
		await nameInput.press('Enter')
		await page.keyboard.press('Escape')

		// It now appears as a View in the left nav; clicking it opens the View.
		const navItem = page.locator('.app-navigation .app-navigation-entry-link', { hasText: state.viewName }).first()
		await expect(navItem).toBeVisible({ timeout: 10_000 })
		await navItem.click()

		// The opened View re-runs the saved filter across boards: labelled cards
		// only, spanning both boards.
		await expect(page.locator('.views-view__row-title', { hasText: 'Match A ' + tag })).toHaveCount(1)
		await expect(page.locator('.views-view__row-title', { hasText: 'Match B ' + tag })).toHaveCount(1)
		await expect(page.locator('.views-view__row-title', { hasText: 'Other A ' + tag })).toHaveCount(0)

		// Cards are grouped under their real board name (not a generic placeholder).
		await expect(page.locator('.views-view__group-title', { hasText: 'ViewsA ' + tag })).toBeVisible()
		await expect(page.locator('.views-view__group-title', { hasText: 'ViewsB ' + tag })).toBeVisible()

		// Persisted server-side (per-user config), independent of the client.
		const persisted = await api('GET', '/views')
		expect((persisted.views ?? []).map((v) => v.name)).toContain(state.viewName)

		expect(consoleErrors, 'no new console errors on the Views surface').toEqual([])
	})
})

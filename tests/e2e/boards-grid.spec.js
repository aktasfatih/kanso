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

// #3569 — Boards page grid v1.
test.describe('Boards page — grid v1', () => {
	const state = { active: 0, archived: 0, stamp: Math.floor(Date.now() / 1000) }

	test.beforeAll(async () => {
		// An active board with a card, so the meta line has a non-zero count.
		const active = await api('POST', '/boards', { title: 'Grid Active ' + state.stamp })
		state.active = active.id
		const stack = await api('POST', '/stacks', { boardId: active.id, title: 'To Do' })
		await api('POST', '/cards', { stackId: stack.id, title: 'A card' })

		// A second board that we archive, to exercise the Active/Archived toggle.
		const arch = await api('POST', '/boards', { title: 'Grid Archived ' + state.stamp })
		state.archived = arch.id
		await api('PATCH', `/boards/${arch.id}`, { archived: true })
	})

	test.afterAll(async () => {
		if (state.active) await api('DELETE', `/boards/${state.active}`).catch(() => {})
		if (state.archived) await api('DELETE', `/boards/${state.archived}`).catch(() => {})
	})

	test('renders the grid with stat meta, search filters, and the toggle switches sets', async ({ page }) => {
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)
		await page.waitForSelector('.board-list-view', { timeout: 15_000 })

		// The grid renders with tiles.
		await expect(page.locator('.board-grid').first()).toBeVisible({ timeout: 10_000 })
		const activeTile = page.locator('.board-tile', { hasText: 'Grid Active ' + state.stamp })
		await expect(activeTile.first()).toBeVisible({ timeout: 10_000 })

		// The tile shows the stats meta line: a card count and a progress bar.
		await expect(activeTile.first().locator('.board-tile__meta')).toContainText(/card/)
		await expect(activeTile.first().locator('.board-tile__progress-track')).toBeVisible()

		// Search filters the active set down.
		const searchBox = page.locator('.board-list-search__input')
		await searchBox.fill('Grid Active ' + state.stamp)
		await expect(page.locator('.board-tile', { hasText: 'Grid Active ' + state.stamp }).first())
			.toBeVisible({ timeout: 6_000 })
		await searchBox.fill('zzz-no-such-board-' + state.stamp)
		await expect(page.locator('.board-section__empty')).toBeVisible({ timeout: 6_000 })
		await searchBox.fill('')

		// The Active/Archived toggle switches the visible set.
		await expect(page.locator('.board-tile__title', { hasText: 'Grid Archived ' + state.stamp }))
			.toHaveCount(0)
		await page.getByRole('button', { name: /Archived/ }).click()
		await expect(page.locator('.board-tile__title', { hasText: 'Grid Archived ' + state.stamp }).first())
			.toBeVisible({ timeout: 6_000 })
		// Active board is not in the archived set.
		await expect(page.locator('.board-tile__title', { hasText: 'Grid Active ' + state.stamp }))
			.toHaveCount(0)
	})
})

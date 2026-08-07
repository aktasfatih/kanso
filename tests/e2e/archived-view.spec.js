// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3595 — routed, virtualized Archived cards page. A card archived via the API
// disappears from the board and shows on /board/:id/archived; unarchiving it from
// the page removes it from the list and returns it to the board.

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

test.describe('Archived cards page', () => {
	const state = { boardId: 0, stackId: 0, cardId: 0, boardUrl: '' }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: `Archived E2E ${Date.now()}` })
		state.boardId = board.id
		const stack = await api('POST', '/stacks', { boardId: board.id, title: 'Backlog' })
		state.stackId = stack.id
		const card = await api('POST', '/cards', { stackId: stack.id, title: 'Archivable Card' })
		state.cardId = card.id
		state.boardUrl = `${BASE}/index.php/apps/kanso#/board/${board.id}`
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('archived card appears on the routed page and unarchive returns it to the board', async ({ page }) => {
		await ncLogin(page)
		await page.goto(state.boardUrl)
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
		await page.waitForSelector('.card-tile', { timeout: 10_000 })

		// Archive the card via the API, then reload so the board reflects it.
		await api('PATCH', `/cards/${state.cardId}`, { archived: true })
		await page.reload()
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})

		// The board has re-hydrated (the ⋯ menu trigger is present) and the card
		// tile is gone from the board.
		await page.waitForSelector('.board-view__more-menu', { timeout: 10_000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Archivable Card' })).not.toBeVisible()

		// The archived action lives in the consolidated ⋯ More overflow menu and is
		// only offered when ≥1 archived card exists. Open the menu, then assert the
		// item is present (carrying the count) — the item renders reactively once
		// the board GET (which carries the archived count) resolves, so the menu can
		// stay open while it appears.
		await page.getByRole('button', { name: 'More' }).click()
		const archivedBtn = page.getByRole('menuitem', { name: /Archived cards \(\d+\)/ })
		await expect(archivedBtn).toBeVisible({ timeout: 10_000 })
		await archivedBtn.click()

		// Routed, deep-linkable page.
		await expect(page).toHaveURL(/#\/board\/\d+\/archived/, { timeout: 8000 })
		await page.waitForSelector('.archived-view', { timeout: 8000 })

		// The card shows in the virtualized archived list.
		const archivedItem = page.locator('.archived-view__row-title').filter({ hasText: 'Archivable Card' })
		await expect(archivedItem).toBeVisible({ timeout: 8000 })

		// Unarchive from the row.
		const row = page.locator('.archived-view__row').filter({ hasText: 'Archivable Card' })
		const unarchiveBtn = row.locator('button', { hasText: 'Unarchive' })
		await expect(unarchiveBtn).toBeVisible({ timeout: 5000 })
		await unarchiveBtn.click()

		// It leaves the list (optimistic removal + db-first reconcile).
		await expect(archivedItem).not.toBeVisible({ timeout: 8000 })

		// Back to the board via the header affordance; card is back on the board.
		await page.locator('.archived-view__back').click()
		await page.waitForSelector('.board-view__header', { timeout: 8000 })
		await expect(page.locator('.card-tile').filter({ hasText: 'Archivable Card' })).toBeVisible({ timeout: 10_000 })
	})

	test('the Archived page is deep-linkable and shows an empty state when nothing is archived', async ({ page }) => {
		// Ensure the card is not archived (previous test unarchived it).
		await api('PATCH', `/cards/${state.cardId}`, { archived: false }).catch(() => {})

		await ncLogin(page)
		await page.goto(`${state.boardUrl}/archived`)
		await page.waitForSelector('.archived-view', { timeout: 12_000 })
		await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {})

		// Empty state renders (no archived cards).
		await expect(page.locator('.archived-view__empty')).toBeVisible({ timeout: 8000 })
		await expect(page.getByText('No archived cards')).toBeVisible()
	})
})

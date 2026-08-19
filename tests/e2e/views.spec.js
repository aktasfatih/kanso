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

	test('the cross-board feed returns a capped envelope of cards from every readable board', async () => {
		// The feed is a bounded envelope { cards, capped, total, limit } (#3892) -
		// not a bare array - so a huge readable set can never ship one unbounded
		// payload. With only a handful of test cards it is well under the cap.
		const feed = await api('GET', '/views/cards')
		expect(Array.isArray(feed.cards)).toBe(true)
		expect(typeof feed.capped).toBe('boolean')
		expect(feed.limit).toBeGreaterThan(0)
		expect(feed.total).toBe(feed.cards.length + (feed.capped ? feed.total - feed.cards.length : 0))
		expect(feed.cards.length).toBeLessThanOrEqual(feed.limit)

		const titles = feed.cards.map((c) => c.title)
		expect(titles).toContain(state.cardA)
		expect(titles).toContain(state.cardB)
		// Each card carries its board identity for grouping + deep-link.
		const rowA = feed.cards.find((c) => c.title === state.cardA)
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

	test('Kanban display groups the feed into columns; a tile opens its own board (#3886)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		// A View spanning both boards, filtered to the two per-board labels so it
		// resolves to EXACTLY the two test cards, grouped by BOARD and saved with
		// the new Kanban display so it re-seeds Kanban on reload.
		const created = await api('PUT', '/views', {
			name: 'Views kanban ' + Math.floor(Date.now() / 1000),
			filter: { labels: [state.labelA, state.labelB] },
			groupBy: 'board',
			display: 'kanban',
		})
		const view = created.views[created.views.length - 1]
		const kanbanViewId = view.id
		expect(kanbanViewId).toBeTruthy()

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${kanbanViewId}`)

			// Saved display:'kanban' re-seeds the Kanban surface on load (no click needed),
			// and the Kanban button reads as active.
			const kanbanBtn = page.locator('.view-page__display-btn', { hasText: 'Kanban' })
			await expect(kanbanBtn).toHaveClass(/view-page__display-btn--active/, { timeout: 15_000 })

			// One column per board group (grouped by board) → ≥2 columns with the
			// expected board-name headers.
			const columns = page.locator('.view-kanban-col')
			await expect(columns).toHaveCount(2, { timeout: 15_000 })
			await expect(page.locator('.view-kanban-col__title', { hasText: /ViewsBoardA/ })).toBeVisible()
			await expect(page.locator('.view-kanban-col__title', { hasText: /ViewsBoardB/ })).toBeVisible()

			// Each card renders as a CardTile; both test cards are present.
			await expect(page.locator('.card-tile__title', { hasText: state.cardA })).toBeVisible({ timeout: 10_000 })
			const tileB = page.locator('.card-tile__title', { hasText: state.cardB })
			await expect(tileB).toBeVisible()

			// Clicking a tile opens the card modal on its OWN board (cross-board
			// correct): card B lives on board B, so the deep-link id must be boardB.
			await tileB.click()
			await expect(page).toHaveURL(new RegExp(`/board/${state.boardB}/card/${state.cardBId}`), { timeout: 15_000 })

			// Close the modal and switch to List, then back to Kanban via the switcher
			// to prove the toggle works in-session too.
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${kanbanViewId}`)
			await page.locator('.view-page__display-btn', { hasText: 'List' }).click()
			await expect(page.locator('.board-list-row__title', { hasText: state.cardA })).toBeVisible({ timeout: 10_000 })
			await kanbanBtn.click()
			await expect(columns.first()).toBeVisible({ timeout: 10_000 })

			// Reload → the saved display:'kanban' still re-seeds Kanban.
			await page.reload()
			await expect(kanbanBtn).toHaveClass(/view-page__display-btn--active/, { timeout: 15_000 })
			await expect(page.locator('.view-kanban-col').first()).toBeVisible({ timeout: 10_000 })
		} finally {
			await api('DELETE', `/views/${kanbanViewId}`).catch(() => {})
		}
	})

	test('richer filter dimensions + new group-by narrow a View (#3815)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })

		// Seed distinguishing summary fields on the two test cards so the new filter
		// dimensions + group-by have something deterministic to bite on:
		//   - card A: type=bug + one comment (commentCount>0)
		//   - card B: type=feature + no comment
		// Both are owned by the same admin user (creator), so owner grouping/filter
		// keeps both, while type/comments narrow to exactly one.
		await api('PATCH', `/cards/${state.cardAId}`, { type: 'bug' })
		await api('PATCH', `/cards/${state.cardBId}`, { type: 'feature' })
		await api('POST', `/cards/${state.cardAId}/comments`, { body: 'a comment for filtering' })

		// A View spanning both boards, filtered to the two per-board labels so it
		// resolves to EXACTLY the two test cards, grouped by TYPE (a new group-by).
		const created = await api('PUT', '/views', {
			name: 'Views filters ' + Math.floor(Date.now() / 1000),
			filter: { labels: [state.labelA, state.labelB] },
			groupBy: 'type',
			display: 'list',
		})
		const view = created.views[created.views.length - 1]
		const filterViewId = view.id
		expect(filterViewId).toBeTruthy()

		try {
			await ncLogin(page)
			await page.goto(`${BASE}/index.php/apps/kanso#/views/${filterViewId}`)

			// Baseline: both cards visible, and the new TYPE group-by renders a "Bug"
			// and a "Feature" group header.
			const rowA = page.locator('.board-list-row__title', { hasText: state.cardA })
			const rowB = page.locator('.board-list-row__title', { hasText: state.cardB })
			await expect(rowA).toBeVisible({ timeout: 15_000 })
			await expect(rowB).toBeVisible({ timeout: 15_000 })
			await expect(page.locator('.board-list-group__title', { hasText: /^Bug$/ })).toBeVisible()
			await expect(page.locator('.board-list-group__title', { hasText: /^Feature$/ })).toBeVisible()

			// ── New filter dimension #1: TYPE (multi-select, OR within) ──────────────
			// Open the progressive drill-in filter, drill into Type, pick Bug.
			await page.locator('.board-filter-bar__trigger').click()
			await page.locator('.board-filter-bar__dim-row[data-dim="types"]').click()
			await page.locator('.board-filter-bar__opt', { hasText: /^Bug$/ }).click()
			// Only the bug card (A) survives; the feature card (B) drops out.
			await expect(rowA).toBeVisible({ timeout: 10_000 })
			await expect(rowB).toHaveCount(0, { timeout: 10_000 })

			// Clear filters back to both cards before exercising the next dimension.
			await page.locator('.board-filter-bar__back').click()
			await page.locator('.board-filter-bar__clear').click()
			await expect(rowB).toBeVisible({ timeout: 10_000 })

			// ── New filter dimension #2: COMMENTS (single-select radio) ──────────────
			await page.locator('.board-filter-bar__dim-row[data-dim="comments"]').click()
			await page.locator('.board-filter-bar__opt', { hasText: /Has comments/ }).click()
			// Only card A (which has a comment) remains.
			await expect(rowA).toBeVisible({ timeout: 10_000 })
			await expect(rowB).toHaveCount(0, { timeout: 10_000 })

			// Clear again, close the popover.
			await page.locator('.board-filter-bar__back').click()
			await page.locator('.board-filter-bar__clear').click()
			await page.keyboard.press('Escape')
			await expect(rowB).toBeVisible({ timeout: 10_000 })

			// ── New group-by: switch to REVIEW ──────────────────────────────────────
			// Neither card has a review requested, so a "No review" group appears.
			const groupSelect = page.locator('.view-page__select .vs__dropdown-toggle')
			await groupSelect.click()
			await page.locator('.vs__dropdown-option', { hasText: /^Review$/ }).click()
			await expect(page.locator('.board-list-group__title', { hasText: /No review/ })).toBeVisible({ timeout: 10_000 })
		} finally {
			await api('DELETE', `/views/${filterViewId}`).catch(() => {})
		}
	})

	test('create a view from the nav (UI, not the API) → opens it → inline rename persists (#3891)', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 })
		await ncLogin(page)
		await page.goto(`${BASE}/index.php/apps/kanso#/`)

		// The "New view" create entry is present in the Views nav section (this is the
		// only way to make the first view; it exists even with zero views).
		const newViewEntry = page.locator('.app-nav__view-new').first()
		await expect(newViewEntry).toBeVisible({ timeout: 15_000 })
		await newViewEntry.click()

		// Clicking it creates a view and opens the View surface at /views/:id.
		await expect(page).toHaveURL(/#\/views\/[^/]+$/, { timeout: 15_000 })
		const uiViewId = page.url().split('/views/')[1]
		expect(uiViewId).toBeTruthy()

		// Rename it in place via the editable title; the new name persists to the nav.
		const newName = 'UI View ' + Math.floor(Date.now() / 1000)
		await page.locator('.view-page__title').click()
		const input = page.locator('.view-page__title-input')
		await expect(input).toBeVisible({ timeout: 5_000 })
		await input.fill(newName)
		await input.press('Enter')

		await expect(
			page.locator('.app-navigation').getByText(newName, { exact: true }),
		).toBeVisible({ timeout: 10_000 })

		// Cleanup the UI-created view.
		await api('DELETE', `/views/${uiViewId}`).catch(() => {})
	})
})

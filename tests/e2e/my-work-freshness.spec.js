// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// #3766 — My Work surfaces must not serve stale cache. The cross-board feeds
// (My Tasks / My Reviews / Inbox) are separate TanStack Query caches from the
// per-board cache, and the nav badges keep them mounted for the app's lifetime.
// Before the fix, membership-changing mutations never invalidated them and the
// global staleTime suppressed the mount refetch on navigation — so a
// self-assignment didn't show up in My Tasks until a manual browser reload.
//
// These tests drive the exact reported repro through the UI and assert that
// NO full page reload happens (a window marker survives every navigation):
//  1. self-assign on a board → client-side navigate to My Tasks → card is
//     there; unassign → it disappears again.
//  2. request a review → client-side navigate to My Reviews → request is there.

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
	// Session is preloaded via storageState (see playwright.config.js) for the
	// default admin context — skip the UI login round-trip entirely. Specs that
	// opt out of storageState start with no cookies and fall through to log in.
	if ((await page.context().cookies()).some((c) => c.name === 'nc_username' || c.name === 'nc_session_id')) return
	await page.goto(BASE + '/index.php/login')
	await page.waitForLoadState('domcontentloaded', { timeout: 15_000 }).catch(() => {})
	if (!(await page.locator('#user').isVisible({ timeout: 3000 }).catch(() => false))) return
	await page.fill('#user', USER)
	await page.fill('#password', PASS)
	await page.click('button[type=submit]')
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
	await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
}

/**
 * Same-document (hash-only) navigation — vue-router handles it client-side,
 * exactly like clicking a nav link. Never triggers a page reload, which is the
 * whole point of this spec.
 */
async function gotoHash(page, hash) {
	await page.evaluate((h) => { window.location.hash = h }, hash)
}

test.describe('My Work freshness (#3766)', () => {
	const ts = Date.now()
	const TASK_TITLE = `Fresh SelfAssign ${ts}`
	const REVIEW_TITLE = `Fresh ReviewReq ${ts}`
	const state = { boardId: 0, stackId: 0, taskCardId: 0, reviewCardId: 0 }

	test.beforeAll(async () => {
		const board = await api('POST', '/boards', { title: `MyWork Fresh E2E ${ts}` })
		state.boardId = board.id
		state.stackId = (await api('POST', '/stacks', { boardId: board.id, title: 'To do' })).id
		// Both cards start UNASSIGNED / review-free — the tests mutate via the UI.
		state.taskCardId = (await api('POST', '/cards', { stackId: state.stackId, title: TASK_TITLE })).id
		state.reviewCardId = (await api('POST', '/cards', { stackId: state.stackId, title: REVIEW_TITLE })).id
	})

	test.afterAll(async () => {
		if (state.boardId) await api('DELETE', `/boards/${state.boardId}`).catch(() => {})
	})

	test('self-assign shows in My Tasks after client-side navigation; unassign removes it (exact repro)', async ({ page }) => {
		await ncLogin(page)

		// Prime the My Tasks cache first — this is what made the bug bite: the
		// feed was fetched once here and then served stale after the mutation.
		await page.goto(`${BASE}/index.php/apps/kanso#/my-tasks`)
		await expect(page.locator('.my-cards-view')).toBeVisible({ timeout: 15_000 })
		const row = page.locator('.my-cards-view__row', { hasText: TASK_TITLE })
		await expect(row).toBeHidden()

		// Marker that only survives if NO full page reload happens from here on.
		await page.evaluate(() => { window.__kansoNoReload = true })

		// Client-side navigate to the card and self-assign via the UI.
		await gotoHash(page, `#/board/${state.boardId}/card/${state.taskCardId}`)
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })
		await page.locator('.card-modal__pill--dashed', { hasText: 'Assign' }).first().click()

		// The settle-phase invalidation must refetch the my-cards feed on its own
		// (the nav badges keep it mounted) — no navigation, no reload.
		const feedRefetch = page.waitForResponse(
			(r) => r.url().includes('/api/my-cards') && r.ok(),
			{ timeout: 10_000 },
		)
		await page.locator('.card-modal__popover .card-modal__assign-option').first().click()
		await expect(page.locator('.card-modal__assignee-pill', { hasText: USER })).toBeVisible({ timeout: 8000 })
		await feedRefetch

		// Client-side navigation to My Tasks — the new assignment is there.
		await gotoHash(page, '#/my-tasks')
		await expect(row).toBeVisible({ timeout: 10_000 })
		expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)

		// Unassign from the modal (opened from the feed row) — the card must
		// leave My Tasks on returning, again without a reload.
		await row.click()
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })
		const unassignDone = page.waitForResponse(
			(r) => r.url().includes(`/api/cards/${state.taskCardId}/assignees/`) && r.request().method() === 'DELETE',
			{ timeout: 10_000 },
		)
		await page.locator('.card-modal__assignee-pill', { hasText: USER })
			.locator('.card-modal__pill-x').first().click()
		await unassignDone

		await page.keyboard.press('Escape') // close-to-origin returns to My Tasks
		await expect(page).toHaveURL(/#\/my-tasks/, { timeout: 10_000 })
		await expect(row).toBeHidden({ timeout: 10_000 })
		expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
	})

	test('review request shows in My Reviews after client-side navigation (second surface)', async ({ page }) => {
		await ncLogin(page)

		// Prime the My Reviews cache.
		await page.goto(`${BASE}/index.php/apps/kanso#/reviews`)
		await expect(page.locator('.my-reviews-view')).toBeVisible({ timeout: 15_000 })
		const row = page.locator('.review-row', { hasText: REVIEW_TITLE })
		await expect(row).toBeHidden()

		await page.evaluate(() => { window.__kansoNoReload = true })

		// Client-side navigate to the card and request a review from the owner
		// (a valid single-user path — the owner holds READ on their own board).
		await gotoHash(page, `#/board/${state.boardId}/card/${state.reviewCardId}`)
		await page.waitForSelector('.card-modal__content', { timeout: 15_000 })
		await page.locator('.card-modal__pill--dashed', { hasText: 'Request' }).first().click()

		const feedRefetch = page.waitForResponse(
			(r) => r.url().includes('/api/reviews/mine') && r.ok(),
			{ timeout: 10_000 },
		)
		await page.locator('.card-modal__popover .card-modal__assign-option').first().click()
		await expect(page.locator('.card-modal__review-pill--pending')).toBeVisible({ timeout: 8000 })
		await feedRefetch

		// Client-side navigation to My Reviews — the new request is there.
		await gotoHash(page, '#/reviews')
		await expect(row).toBeVisible({ timeout: 10_000 })
		expect(await page.evaluate(() => window.__kansoNoReload)).toBe(true)
	})
})
